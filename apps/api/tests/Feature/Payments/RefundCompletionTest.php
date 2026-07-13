<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Actions\MarkOrderAwaitingPayment;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Data\CreateOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\TicketStatus;
use App\Orders\Models\Order;
use App\Payments\Actions\CreateRefund;
use App\Payments\Actions\ReconcileProcessingRefunds;
use App\Payments\Consumers\ProjectLedgerEntries;
use App\Payments\Data\CreateRefundData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\FakeWebhookDelivery;
use App\Payments\Gateways\NormalizedPaymentEvent;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use App\Support\Outbox\Jobs\ProcessOutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OrderedConsumption;
use App\Support\Outbox\OutboxSweeper;
use App\Support\Outbox\ProjectionLock;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08b plan, Slices 6 and 7: webhook-driven completion invoking
 * the Orders transitions and voiding, RefundCompleted feeding the
 * ledger with one set under duplicate delivery, the reconciliation
 * sweeper backstop, and the late-webhook no-op.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['refund_commission_policy' => 'returned'])->id,
    );
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('gateway_webhook_events')->delete();
    });

    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant($sentinel, function (): void {
        DB::table('gateway_webhook_events')->delete();
    });

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        foreach (['outbox_deliveries', 'outbox_events', 'media', 'refunds', 'payments', 'tickets', 'order_items', 'orders', 'hold_items', 'holds', 'customers', 'ticket_type_inventory', 'ticket_types', 'events'] as $table) {
            DB::table($table)->where('tenant_id', $this->tenantId)->delete();
        }
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });
});

/**
 * A paid order with real issued tickets and a confirmed payment whose
 * breakdown is persisted.
 *
 * @return array{orderId: string, paymentId: string, ticketIds: list<string>}
 */
function completionFixture(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 50,
            'held' => 0,
            'sold' => 0,
        ]);

        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => sprintf('completion-buyer-%s@example.com', Str::uuid7()),
        ]);

        $holdId = app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            ]),
            $customer->id,
        )->id;

        $orderId = app(ConvertHoldToOrder::class)(
            CreateOrderData::from(['hold_id' => $holdId]),
            $customer->id,
        )->id;

        app(MarkOrderAwaitingPayment::class)($orderId);
        app(MarkOrderPaid::class)($orderId);

        $payment = Payment::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'status' => PaymentStatus::Confirmed,
            'money' => Money::of(10_000, 'USD'),
            'gateway_reference' => 'fake_'.Str::uuid7(),
        ]);

        DB::table('payments')->where('id', $payment->id)->update([
            'fee_amount' => 300,
            'commission_amount' => 500,
            'confirmed_at' => now(),
        ]);

        $ticketIds = DB::table('tickets')->where('order_id', $orderId)->orderBy('id')->pluck('id')->all();

        return ['orderId' => $orderId, 'paymentId' => $payment->id, 'ticketIds' => $ticketIds];
    });
}

function completionRefund(string $tenantId, string $paymentId, ?array $amount = null, ?array $ticketIds = null): Refund
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => app(CreateRefund::class)(
            $paymentId,
            CreateRefundData::from(['amount' => $amount, 'ticket_ids' => $ticketIds]),
            (string) Str::uuid7(),
        )->refund,
    );
}

function deliverRefundWebhook(FakeWebhookDelivery $delivery)
{
    return test()->call('POST', '/v1/webhooks/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $delivery->headers['X-Fake-Signature'],
    ], $delivery->body);
}

/**
 * @return array<string, mixed>
 */
function completionState(string $tenantId, string $refundId, string $orderId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn (): array => [
        'refund' => Refund::query()->findOrFail($refundId),
        'order' => Order::query()->findOrFail($orderId),
        'voided' => DB::table('tickets')->where('order_id', $orderId)->where('status', TicketStatus::Refunded->value)->count(),
        'ticketEvents' => OutboxEvent::query()->where('type', 'TicketRefunded')->count(),
        'completedEvents' => OutboxEvent::query()->where('type', 'RefundCompleted')->where('aggregate_id', $refundId)->count(),
    ]);
}

it('completes a full refund end to end: order refunded, all tickets voided, books balanced', function (): void {
    $fixture = completionFixture($this->tenantId);
    $refund = completionRefund($this->tenantId, $fixture['paymentId']);

    deliverRefundWebhook(app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$refund->id))->assertStatus(200);

    $state = completionState($this->tenantId, $refund->id, $fixture['orderId']);

    expect($state['refund']->status)->toBe(RefundStatus::Completed)
        ->and($state['order']->status)->toBe(OrderStatus::Refunded)
        ->and($state['voided'])->toBe(2)
        ->and($state['ticketEvents'])->toBe(2)
        ->and($state['completedEvents'])->toBe(1);

    // The ordered ledger projection catches up through the sweeper.
    $this->travel(config()->integer('outbox.sweeper_grace_seconds') + 1)->seconds();
    app(OutboxSweeper::class)->sweep();

    $legs = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('ledger_entries')->where('reference_id', $refund->id)->get(),
    );

    // Returned policy: credit receivable 10000, debit tenant_net 9500,
    // debit platform_commission 500.
    expect($legs)->toHaveCount(3)
        ->and($legs->where('direction', 'debit')->sum('amount'))->toBe($legs->where('direction', 'credit')->sum('amount'));
});

it('accumulates two partial refunds into refunded with selective then remaining voiding', function (): void {
    $fixture = completionFixture($this->tenantId);

    $first = completionRefund($this->tenantId, $fixture['paymentId'], ['amount' => 4_000, 'currency' => 'USD'], [$fixture['ticketIds'][0]]);
    deliverRefundWebhook(app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$first->id))->assertStatus(200);

    $mid = completionState($this->tenantId, $first->id, $fixture['orderId']);

    expect($mid['order']->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($mid['voided'])->toBe(1);

    $second = completionRefund($this->tenantId, $fixture['paymentId']);
    deliverRefundWebhook(app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$second->id))->assertStatus(200);

    $end = completionState($this->tenantId, $second->id, $fixture['orderId']);

    expect($end['refund']->status)->toBe(RefundStatus::Completed)
        ->and($end['order']->status)->toBe(OrderStatus::Refunded)
        ->and($end['voided'])->toBe(2);
});

it('treats a duplicate completion webhook as one outcome and one void pass', function (): void {
    $fixture = completionFixture($this->tenantId);
    $refund = completionRefund($this->tenantId, $fixture['paymentId']);

    deliverRefundWebhook(app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$refund->id))->assertStatus(200);
    deliverRefundWebhook(app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$refund->id))->assertStatus(200);

    $state = completionState($this->tenantId, $refund->id, $fixture['orderId']);

    expect($state['ticketEvents'])->toBe(2)
        ->and($state['completedEvents'])->toBe(1);
});

it('produces exactly one ledger set under duplicate RefundCompleted delivery', function (): void {
    $fixture = completionFixture($this->tenantId);
    $refund = completionRefund($this->tenantId, $fixture['paymentId']);

    deliverRefundWebhook(app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$refund->id))->assertStatus(200);

    $eventId = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'RefundCompleted')->where('aggregate_id', $refund->id)->firstOrFail()->id,
    );

    $this->travel(config()->integer('outbox.stability_window_seconds') + 1)->seconds();

    foreach ([1, 2] as $attempt) {
        $job = new ProcessOutboxDelivery($eventId, ProjectLedgerEntries::NAME);
        $job->withFakeQueueInteractions();
        $job->handle(app(TenantTransaction::class), app(SubscriberRegistry::class), app(OrderedConsumption::class), app(ProjectionLock::class));
    }

    $count = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => DB::table('ledger_entries')->where('reference_id', $refund->id)->count(),
    );

    expect($count)->toBe(3);
});

it('routes a refund failure webhook to failed with the order still paid', function (): void {
    $fixture = completionFixture($this->tenantId);
    $refund = completionRefund($this->tenantId, $fixture['paymentId'], ['amount' => 2_000, 'currency' => 'USD']);

    deliverRefundWebhook(app(FakeGateway::class)->refundFailureWebhook('fake_rf_'.$refund->id, 'insufficient_gateway_balance'))->assertStatus(200);

    [$fresh, $payment, $order] = app(TenantTransaction::class)->asTenant($this->tenantId, fn (): array => [
        Refund::query()->findOrFail($refund->id),
        Payment::query()->findOrFail($fixture['paymentId']),
        Order::query()->findOrFail($fixture['orderId']),
    ]);

    expect($fresh->status)->toBe(RefundStatus::Failed)
        ->and($fresh->failure_code)->toBe('insufficient_gateway_balance')
        ->and($payment->refunded_amount)->toBe(0)
        ->and($order->status)->toBe(OrderStatus::Paid);
});

it('resolves a stranded processing refund through the reconciliation sweeper, with a late webhook a no-op', function (): void {
    $fixture = completionFixture($this->tenantId);
    $refund = completionRefund($this->tenantId, $fixture['paymentId']);

    app(FakeGatewayScenarios::class)->scriptQueryResult(
        'fake_rf_'.$refund->id,
        NormalizedPaymentEvent::refundCompleted('fake_rf_'.$refund->id),
    );

    $this->travel((int) config('payments.reconcile_grace_seconds') + 1)->seconds();

    expect(app(ReconcileProcessingRefunds::class)())->toBe(1);

    $state = completionState($this->tenantId, $refund->id, $fixture['orderId']);

    expect($state['refund']->status)->toBe(RefundStatus::Completed)
        ->and($state['order']->status)->toBe(OrderStatus::Refunded)
        ->and($state['voided'])->toBe(2);

    deliverRefundWebhook(app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$refund->id))->assertStatus(200);

    $after = completionState($this->tenantId, $refund->id, $fixture['orderId']);

    expect($after['ticketEvents'])->toBe(2)
        ->and($after['completedEvents'])->toBe(1);
});
