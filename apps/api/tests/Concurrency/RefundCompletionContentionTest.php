<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Actions\MarkOrderRefunded;
use App\Orders\Enums\OrderStatus;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Orders\Models\Order;
use App\Payments\Actions\CompleteRefund;
use App\Payments\Actions\FailRefund;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08b plan, Slices 6 and 7 concurrency rules: parallel completion
 * and failure racing processing produce exactly one outcome, a failed
 * outcome releases the reservation exactly once, and the order
 * transition to refunded resolves by affected-row count.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

function contentionRefundFixture(): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
        $event = Event::factory()->create(['tenant_id' => $tenantId]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $event->id,
            'status' => OrderStatus::Paid,
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'status' => PaymentStatus::Confirmed,
            'money' => Money::of(5_000, 'USD'),
            'gateway_reference' => 'fake_'.Str::uuid7(),
        ]);

        DB::table('payments')->where('id', $payment->id)->update([
            'refunded_amount' => 2_000,
            'refunded_commission_amount' => 0,
        ]);

        $refund = Refund::factory()->create([
            'tenant_id' => $tenantId,
            'payment_id' => $payment->id,
            'money' => Money::of(2_000, 'USD'),
            'status' => RefundStatus::Processing,
            'gateway_reference' => 'fake_rf_'.Str::uuid7(),
        ]);

        return ['tenantId' => $tenantId, 'orderId' => $order->id, 'paymentId' => $payment->id, 'refundId' => $refund->id];
    });
}

function cleanContentionFixture(string $tenantId): void
{
    app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
        foreach (['outbox_deliveries', 'outbox_events', 'refunds', 'payments', 'tickets', 'orders', 'customers', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function () use ($tenantId): void {
        Tenant::query()->whereKey($tenantId)->delete();
    });
}

it('admits exactly one outcome when completion and failure race a processing refund', function (): void {
    $fx = contentionRefundFixture();

    $results = ParallelRunner::runEach(
        function () use ($fx): string {
            $applied = app(TenantTransaction::class)->asTenant(
                $fx['tenantId'],
                fn () => app(CompleteRefund::class)($fx['refundId']),
            );

            return $applied !== null ? 'completed' : 'lost';
        },
        function () use ($fx): string {
            $applied = app(TenantTransaction::class)->asTenant(
                $fx['tenantId'],
                fn () => app(FailRefund::class)($fx['refundId'], 'raced'),
            );

            return $applied !== null ? 'failed' : 'lost';
        },
    );

    [$refund, $payment] = app(TenantTransaction::class)->asTenant($fx['tenantId'], fn (): array => [
        Refund::query()->findOrFail($fx['refundId']),
        Payment::query()->findOrFail($fx['paymentId']),
    ]);

    $winners = array_values(array_filter($results, fn (string $r): bool => $r !== 'lost'));

    expect($winners)->toHaveCount(1)
        ->and($refund->status->value)->toBe($winners[0])
        ->and($payment->refunded_amount)->toBe($winners[0] === 'failed' ? 0 : 2_000);

    cleanContentionFixture($fx['tenantId']);
});

it('releases the reservation exactly once under parallel duplicate failures', function (): void {
    $fx = contentionRefundFixture();

    ParallelRunner::run(4, function () use ($fx): string {
        $applied = app(TenantTransaction::class)->asTenant(
            $fx['tenantId'],
            fn () => app(FailRefund::class)($fx['refundId'], 'raced'),
        );

        return $applied !== null ? 'released' : 'noop';
    });

    $payment = app(TenantTransaction::class)->asTenant(
        $fx['tenantId'],
        fn () => Payment::query()->findOrFail($fx['paymentId']),
    );

    expect($payment->refunded_amount)->toBe(0);

    cleanContentionFixture($fx['tenantId']);
});

it('resolves the order transition to refunded by affected-row count under a parallel duplicate', function (): void {
    $fx = contentionRefundFixture();

    $results = ParallelRunner::run(2, function () use ($fx): string {
        try {
            app(TenantTransaction::class)->asTenant(
                $fx['tenantId'],
                fn () => app(MarkOrderRefunded::class)($fx['orderId']),
            );

            return 'won';
        } catch (InvalidOrderTransitionException) {
            return 'refused';
        }
    });

    $order = app(TenantTransaction::class)->asTenant(
        $fx['tenantId'],
        fn () => Order::query()->findOrFail($fx['orderId']),
    );

    expect(array_count_values($results)['won'] ?? 0)->toBe(1)
        ->and($order->status)->toBe(OrderStatus::Refunded);

    cleanContentionFixture($fx['tenantId']);
});
