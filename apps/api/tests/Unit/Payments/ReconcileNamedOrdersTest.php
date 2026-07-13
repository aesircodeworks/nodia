<?php

use App\EventCatalog\Models\Event;
use App\Identity\Models\Customer;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Actions\ReconcileNamedOrders;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\NormalizedPaymentEvent;
use App\Payments\Models\Payment;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 5, task breakdown item 12 unit invariants:
 * candidates() alone never mutates anything (a dry-run investigation is
 * safe to run freely), and the matched-versus-resolved counts stay
 * distinguishable when a named order carries no initiated payment to
 * poll.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-13T12:00:00Z'));

    // Confirming a payment for real records PaymentConfirmed, which
    // QUEUE_CONNECTION=sync would deliver synchronously to Orders'
    // HandlePaymentConfirmed consumer; that consumer expects a real hold
    // with items to issue tickets from, which these order fixtures never
    // build (out of scope for this unit-level suite, mirroring
    // OutboxArchivalTest's own Queue::fake() posture for the same reason).
    Queue::fake();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();

    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('payments')->where('tenant_id', $this->tenantId)->delete();
        DB::table('orders')->where('tenant_id', $this->tenantId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        // activity_log grants nodia_platform a DELETE restricted to rows
        // past the retention pruner's own cutoff (stage-12 plan, task
        // breakdown item 8): an ordinary delete with no cutoff set
        // affects zero rows, so entries this file records are left in
        // place (mirroring ReplayFailedOutboxCommandTest's own posture)
        // and every assertion below scopes by a unique operator instead
        // of an exact count.
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

function unitReconcileAwaitingOrder(string $tenantId): Order
{
    return app(TenantTransaction::class)->asTenant($tenantId, fn () => Order::factory()->create([
        'tenant_id' => $tenantId,
        'customer_id' => Customer::factory()->create(['tenant_id' => $tenantId])->id,
        'event_id' => Event::factory()->create(['tenant_id' => $tenantId])->id,
        'status' => OrderStatus::AwaitingPayment,
    ]));
}

it('candidates() lists matching orders without writing anything', function (): void {
    $order = unitReconcileAwaitingOrder($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Payment::factory()->create([
        'tenant_id' => $this->tenantId,
        'order_id' => $order->id,
        'gateway_reference' => 'fake_unit_candidates',
    ]));

    $candidates = app(ReconcileNamedOrders::class)->candidates([], $this->tenantId, CarbonImmutable::now());

    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()->id)->toBe($order->id);

    $payment = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payment::query()->where('order_id', $order->id)->firstOrFail(),
    );

    expect($payment->status)->toBe(PaymentStatus::Initiated);
});

it('reconcile(execute: false) resolves nothing and still records one activity log entry', function (): void {
    $order = unitReconcileAwaitingOrder($this->tenantId);

    $summary = app(ReconcileNamedOrders::class)->reconcile(
        operator: 'unit-operator-dry-run',
        execute: false,
        orderIds: [$order->id],
        tenantId: null,
        before: CarbonImmutable::now(),
    );

    expect($summary->orders)->toHaveCount(1)
        ->and($summary->resolved)->toBe(0);

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'payments_reconcile_orders_invoked')
            ->where('properties->operator', 'unit-operator-dry-run')
            ->latest('created_at')
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->properties['operator'])->toBe('unit-operator-dry-run')
        ->and($entry->properties['execute'])->toBeFalse()
        ->and($entry->properties['orders_matched'])->toBe(1)
        ->and($entry->properties['orders_resolved'])->toBe(0);
});

it('distinguishes matched orders from resolved ones when a named order has no initiated payment', function (): void {
    $withPayment = unitReconcileAwaitingOrder($this->tenantId);
    $withoutPayment = unitReconcileAwaitingOrder($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, fn () => Payment::factory()->create([
        'tenant_id' => $this->tenantId,
        'order_id' => $withPayment->id,
        'gateway_reference' => 'fake_unit_resolve',
    ]));

    app(FakeGatewayScenarios::class)->scriptQueryResult(
        'fake_unit_resolve',
        NormalizedPaymentEvent::confirmed('fake_unit_resolve', Money::of(100, 'USD')),
    );

    $summary = app(ReconcileNamedOrders::class)->reconcile(
        operator: 'unit-operator',
        execute: true,
        orderIds: [$withPayment->id, $withoutPayment->id],
        tenantId: null,
        before: CarbonImmutable::now(),
    );

    expect($summary->orders)->toHaveCount(2)
        ->and($summary->resolved)->toBe(1);

    $payment = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Payment::query()->where('order_id', $withPayment->id)->firstOrFail(),
    );

    expect($payment->status)->toBe(PaymentStatus::Confirmed);
});
