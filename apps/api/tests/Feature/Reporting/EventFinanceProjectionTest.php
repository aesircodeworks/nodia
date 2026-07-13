<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Actions\ConfirmPayment;
use App\Payments\Actions\CreateRefund;
use App\Payments\Data\CreateRefundData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Models\Payment;
use App\Reporting\Jobs\ProjectEventFinance;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-11 plan, Slice 3 Feature tests: PaymentConfirmed and
 * RefundCompleted delivered through the real pipeline (QUEUE_CONNECTION
 * =sync stands in for Redis locally), never reading ledger_entries, plus
 * the mandated duplicate-delivery test for each event type.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    DB::statement('truncate ledger_entries');

    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('gateway_webhook_events')->where('tenant_id', $tenantId)->delete();

            foreach (['report_event_finance', 'outbox_deliveries', 'outbox_events', 'refunds', 'payments', 'orders', 'customers', 'events'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @return array{tenantId: string, eventId: string}
 */
function financeTenantAndEvent(array $tenantOverrides = []): array
{
    $tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create([
            'commission_bps' => 250,
            'enabled_gateways' => ['fake'],
            ...$tenantOverrides,
        ])->id,
    );

    $eventId = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId])->id,
    );

    return ['tenantId' => $tenantId, 'eventId' => $eventId];
}

/**
 * A confirmed payment against a bare order (mirrors
 * tests/Feature/Payments/LedgerProjectionTest.php's confirmedPaymentEvent):
 * ConfirmPayment is the real domain transition that persists fee_amount
 * and commission_amount, and records PaymentConfirmed in the same
 * transaction. The sync queue already delivers it once, including to
 * ProjectEventFinance, before this function returns.
 *
 * @return array{payment: Payment, event: OutboxEvent, orderId: string}
 */
function financeConfirmedPayment(string $tenantId, string $eventId, int $amount, int $fee): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $amount, $fee): array {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
        $order = Order::factory()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $customer->id,
            'event_id' => $eventId,
            'status' => OrderStatus::Paid,
        ]);

        $payment = Payment::factory()->create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'status' => PaymentStatus::Initiated,
            'money' => Money::of($amount, 'USD'),
        ]);

        app(ConfirmPayment::class)($payment->id, Money::of($fee, 'USD'));

        $event = OutboxEvent::query()
            ->where('type', 'PaymentConfirmed')
            ->where('aggregate_id', $payment->id)
            ->firstOrFail();

        return ['payment' => $payment->fresh(), 'event' => $event, 'orderId' => $order->id];
    });
}

/**
 * A confirmed payment plus a full refund delivered end to end through
 * FakeGateway's webhook, mirroring
 * tests/Feature/Payments/RefundCompletionTest.php's completionFixture
 * and completionRefund. No tickets are issued: CreateRefund's ticket
 * check only fires for a partial refund's explicit ticket_ids, and a
 * full refund (ticket_ids null) voids none when the order has none,
 * which App\Orders\Actions\MarkTicketsRefunded tolerates.
 *
 * @return array{refundId: string, event: OutboxEvent}
 */
function financeCompletedRefund(string $tenantId, string $eventId, int $amount, int $fee): array
{
    $payment = financeConfirmedPayment($tenantId, $eventId, $amount, $fee)['payment'];

    $refundId = app(TenantTransaction::class)->asTenant($tenantId, function () use ($payment): string {
        DB::table('payments')->where('id', $payment->id)->update(['gateway_reference' => 'fake_'.Str::uuid7()]);

        return app(CreateRefund::class)(
            $payment->id,
            CreateRefundData::from(['amount' => null, 'ticket_ids' => null]),
            (string) Str::uuid7(),
        )->refund->id;
    });

    // The webhook endpoint resolves its own tenant from the gateway
    // reference across every tenant, mirroring
    // RefundCompletionTest::deliverRefundWebhook: called with no tenant
    // transaction already open, never nested inside asTenant.
    $delivery = app(FakeGateway::class)->refundCompletionWebhook('fake_rf_'.$refundId);

    test()->call('POST', '/v1/webhooks/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $delivery->headers['X-Fake-Signature'],
    ], $delivery->body)->assertStatus(200);

    $event = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => OutboxEvent::query()
            ->where('type', 'RefundCompleted')
            ->where('aggregate_id', $refundId)
            ->firstOrFail(),
    );

    return ['refundId' => $refundId, 'event' => $event];
}

/**
 * @return object|null
 */
function financeRow(string $tenantId, string $eventId)
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => DB::table('report_event_finance')->where('event_id', $eventId)->first(),
    );
}

it('produces one finance row whose four amount columns equal the payload amount plus the payment row fee and commission facts', function (): void {
    $fx = financeTenantAndEvent();
    financeConfirmedPayment($fx['tenantId'], $fx['eventId'], 10_000, 300);

    $row = financeRow($fx['tenantId'], $fx['eventId']);

    // commission_bps 250 of gross 10000 = 250.
    expect((int) $row->orders_paid_count)->toBe(1)
        ->and((int) $row->refunds_count)->toBe(0)
        ->and((int) $row->gross_amount)->toBe(10_000)
        ->and((int) $row->gateway_fee_amount)->toBe(300)
        ->and((int) $row->platform_commission_amount)->toBe(250)
        ->and((int) $row->tenant_net_amount)->toBe(9_450)
        ->and((int) $row->refunded_amount)->toBe(0)
        ->and($row->currency)->toBe('USD');
});

it('increments the same row when a second payment for the same event is confirmed', function (): void {
    $fx = financeTenantAndEvent();
    financeConfirmedPayment($fx['tenantId'], $fx['eventId'], 10_000, 300);
    financeConfirmedPayment($fx['tenantId'], $fx['eventId'], 5_000, 150);

    $row = financeRow($fx['tenantId'], $fx['eventId']);

    expect((int) $row->orders_paid_count)->toBe(2)
        ->and((int) $row->gross_amount)->toBe(15_000)
        ->and((int) $row->gateway_fee_amount)->toBe(450);
});

it('projects a full refund correctly under the returned commission policy', function (): void {
    $fx = financeTenantAndEvent(['refund_commission_policy' => 'returned']);
    financeCompletedRefund($fx['tenantId'], $fx['eventId'], 10_000, 300);

    $row = financeRow($fx['tenantId'], $fx['eventId']);

    // gross 10000, fee 300, commission_bps 250 -> commission 250, fully
    // returned on a full refund. gross and commission net down by the
    // refund; fee is untouched (the gateway never returns it).
    expect((int) $row->orders_paid_count)->toBe(1)
        ->and((int) $row->refunds_count)->toBe(1)
        ->and((int) $row->gross_amount)->toBe(0)
        ->and((int) $row->gateway_fee_amount)->toBe(300)
        ->and((int) $row->platform_commission_amount)->toBe(0)
        ->and((int) $row->tenant_net_amount)->toBe(-300)
        ->and((int) $row->refunded_amount)->toBe(10_000)
        ->and($row->gross_amount - $row->gateway_fee_amount - $row->platform_commission_amount)->toBe((int) $row->tenant_net_amount);
});

it('projects a full refund correctly under the retained commission policy, the whole amount debiting tenant_net', function (): void {
    $fx = financeTenantAndEvent(['refund_commission_policy' => 'retained']);
    financeCompletedRefund($fx['tenantId'], $fx['eventId'], 10_000, 300);

    $row = financeRow($fx['tenantId'], $fx['eventId']);

    // Retained: platform keeps the whole commission (250), so the refund
    // debits tenant_net by the full 10000 and leaves commission untouched.
    expect((int) $row->refunds_count)->toBe(1)
        ->and((int) $row->gross_amount)->toBe(0)
        ->and((int) $row->gateway_fee_amount)->toBe(300)
        ->and((int) $row->platform_commission_amount)->toBe(250)
        ->and((int) $row->tenant_net_amount)->toBe(-550)
        ->and((int) $row->refunded_amount)->toBe(10_000)
        ->and($row->gross_amount - $row->gateway_fee_amount - $row->platform_commission_amount)->toBe((int) $row->tenant_net_amount);
});

it('converges to the correct finance figures with zero ledger_entries rows present, proving it reads no ledger data', function (): void {
    $fx = financeTenantAndEvent(['refund_commission_policy' => 'returned']);
    financeCompletedRefund($fx['tenantId'], $fx['eventId'], 10_000, 300);

    $ledgerCount = app(TenantTransaction::class)->asTenant(
        $fx['tenantId'],
        fn () => DB::table('ledger_entries')->count(),
    );

    $row = financeRow($fx['tenantId'], $fx['eventId']);

    // The ordered ledger projection is deferred by the Stage 4 stability
    // window and has not run yet, while this plain unordered subscriber
    // already has (system-design 9.2: no cross-consumer ordering).
    expect($ledgerCount)->toBe(0)
        ->and((int) $row->orders_paid_count)->toBe(1)
        ->and((int) $row->refunds_count)->toBe(1)
        ->and((int) $row->tenant_net_amount)->toBe(-300);
});

it('causes exactly one increment when a PaymentConfirmed delivery is repeated', function (): void {
    $fx = financeTenantAndEvent();
    $confirmed = financeConfirmedPayment($fx['tenantId'], $fx['eventId'], 10_000, 300);

    processOutboxDeliveryTwice($confirmed['event']->id, ProjectEventFinance::NAME);

    $row = financeRow($fx['tenantId'], $fx['eventId']);

    expect((int) $row->orders_paid_count)->toBe(1)
        ->and((int) $row->gross_amount)->toBe(10_000);
});

it('causes exactly one increment when a RefundCompleted delivery is repeated', function (): void {
    $fx = financeTenantAndEvent();
    $refund = financeCompletedRefund($fx['tenantId'], $fx['eventId'], 10_000, 300);

    processOutboxDeliveryTwice($refund['event']->id, ProjectEventFinance::NAME);

    $row = financeRow($fx['tenantId'], $fx['eventId']);

    expect((int) $row->refunds_count)->toBe(1)
        ->and((int) $row->refunded_amount)->toBe(10_000);
});
