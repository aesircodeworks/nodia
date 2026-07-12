<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Actions\CreateRefund;
use App\Payments\Consumers\ExecuteRefund;
use App\Payments\Data\CreateRefundData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use App\Payments\Exceptions\GatewayUnavailableException;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08b plan, Slice 6: the RefundInitiated executor calls the
 * gateway once (pending to processing admits one executor even under
 * duplicate delivery), a declined refund lands failed with its
 * reservation released, and the retry budget rides the per-subscriber
 * policy (3 attempts at 1s, 5s, 15s).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['refund_commission_policy' => 'returned'])->id,
    );

    [$this->orderId, $this->paymentId] = app(TenantTransaction::class)->asTenant($this->tenantId, function (): array {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenantId]);
        $event = Event::factory()->create(['tenant_id' => $this->tenantId]);

        $order = Order::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $this->tenantId,
            'order_id' => $order->id,
            'status' => PaymentStatus::Confirmed,
            'money' => Money::of(10_000, 'USD'),
            'gateway_reference' => 'fake_'.Str::uuid7(),
        ]);

        DB::table('payments')->where('id', $payment->id)->update(['commission_amount' => 500]);

        return [$order->id, $payment->id];
    });
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['outbox_deliveries', 'outbox_events', 'refunds', 'payments', 'orders', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function executionRefund(string $tenantId, string $paymentId, int $amount = 2_500): Refund
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => app(CreateRefund::class)(
            $paymentId,
            CreateRefundData::from(['amount' => ['amount' => $amount, 'currency' => 'USD']]),
            (string) Str::uuid7(),
        )->refund,
    );
}

function freshRefund(string $tenantId, string $refundId): Refund
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Refund::query()->findOrFail($refundId),
    );
}

it('executes an accepted refund to processing with the gateway reference persisted', function (): void {
    $refund = executionRefund($this->tenantId, $this->paymentId);

    $fresh = freshRefund($this->tenantId, $refund->id);

    // The sync queue ran the executor on commit of the creating request.
    expect($fresh->status)->toBe(RefundStatus::Processing)
        ->and($fresh->gateway_reference)->toBe('fake_rf_'.$refund->id)
        ->and(app(FakeGatewayScenarios::class)->refundCallsFor($refund->id))->toBe(1);
});

it('calls the gateway once under duplicate RefundInitiated delivery', function (): void {
    $refund = executionRefund($this->tenantId, $this->paymentId);

    $eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'RefundInitiated')->where('aggregate_id', $refund->id)->firstOrFail()->id,
    );

    $job = new ProcessOutboxDelivery($eventId, ExecuteRefund::NAME);
    $job->handle(app(TenantTransaction::class), app(SubscriberRegistry::class), app(OrderedConsumption::class));
    $job->handle(app(TenantTransaction::class), app(SubscriberRegistry::class), app(OrderedConsumption::class));

    expect(app(FakeGatewayScenarios::class)->refundCallsFor($refund->id))->toBe(1)
        ->and(freshRefund($this->tenantId, $refund->id)->status)->toBe(RefundStatus::Processing);
});

it('lands a declined refund on failed with the reservation released and the order untouched', function (): void {
    app(FakeGatewayScenarios::class)->declineNextRefund('refund_rejected');

    $refund = executionRefund($this->tenantId, $this->paymentId);

    [$fresh, $payment, $order] = app(TenantTransaction::class)->asTenant($this->tenantId, fn (): array => [
        Refund::query()->findOrFail($refund->id),
        Payment::query()->findOrFail($this->paymentId),
        Order::query()->findOrFail($this->orderId),
    ]);

    expect($fresh->status)->toBe(RefundStatus::Failed)
        ->and($fresh->failure_code)->toBe('refund_rejected')
        ->and($payment->refunded_amount)->toBe(0)
        ->and($payment->refunded_commission_amount)->toBe(0)
        ->and($order->status)->toBe(OrderStatus::Paid);
});

it('applies the mandated retry budget to the executor subscriber', function (): void {
    $job = new ProcessOutboxDelivery(Str::uuid7()->toString(), ExecuteRefund::NAME);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([1, 5, 15]);
});

it('leaves the refund pending for a retry when the gateway transport fails', function (): void {
    app(FakeGatewayScenarios::class)->failNextRefund();

    try {
        executionRefund($this->tenantId, $this->paymentId);
    } catch (GatewayUnavailableException) {
        // The sync-queue executor ran on commit and its transport failure
        // bubbled up; in production the queued job retries instead.
    }

    $refund = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Refund::query()->where('payment_id', $this->paymentId)->firstOrFail(),
    );

    // The executor transaction rolled back; the delivery stays pending
    // and the refund row never left pending, so the retry (or the
    // outbox sweeper) re-executes cleanly with the same key.
    $fresh = freshRefund($this->tenantId, $refund->id);

    expect($fresh->status)->toBe(RefundStatus::Pending)
        ->and($fresh->gateway_reference)->toBeNull();

    $eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'RefundInitiated')->where('aggregate_id', $refund->id)->firstOrFail()->id,
    );

    $job = new ProcessOutboxDelivery($eventId, ExecuteRefund::NAME);
    $job->handle(app(TenantTransaction::class), app(SubscriberRegistry::class), app(OrderedConsumption::class));

    expect(freshRefund($this->tenantId, $refund->id)->status)->toBe(RefundStatus::Processing);
});
