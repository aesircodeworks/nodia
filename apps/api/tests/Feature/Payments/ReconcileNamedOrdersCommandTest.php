<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\NormalizedPaymentEvent;
use App\Payments\Models\Payment;
use App\Support\Audit\Models\ActivityLogEntry;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-12 plan, Slice 5, task breakdown item 12: payments:reconcile-orders,
 * the dry-run-by-default, --operator-required, activity-logged manual poll
 * of named awaiting_payment orders. Named "-orders" rather than the plan's
 * literal "payments:reconcile" because that signature is already the
 * Stage 8a automatic sweep (App\Console\Commands
 * \ReconcilePendingPaymentsCommand) -- see this task's journal entry.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-07-13T12:00:00Z'));
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('payments')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('media')->where('tenant_id', $tenantId)->delete();
            DB::table('tickets')->where('tenant_id', $tenantId)->delete();
            DB::table('order_items')->where('tenant_id', $tenantId)->delete();
            DB::table('orders')->where('tenant_id', $tenantId)->delete();
            DB::table('hold_items')->where('tenant_id', $tenantId)->delete();
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        ActivityLogEntry::query()->where('event', 'payments_reconcile_orders_invoked')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @return array{tenant: Tenant, host: string, event: Event, ticketType: TicketType}
 */
function reconcileOrdersTenantFixture(): array
{
    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create(['enabled_gateways' => ['fake']]);
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });

    ['event' => $event, 'ticketType' => $ticketType] = app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant): array {
        $event = Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 100,
            'held' => 0,
            'sold' => 0,
        ]);

        return ['event' => $event, 'ticketType' => $ticketType];
    });

    return ['tenant' => $tenant, 'host' => $host, 'event' => $event, 'ticketType' => $ticketType];
}

/**
 * An awaiting_payment order with an initiated async payment, built over
 * the real endpoints against an already-provisioned tenant fixture.
 *
 * @return array{orderId: string, holdId: string, paymentId: string}
 */
function reconcileOrdersOrderFixture(array $tenantFixture, string $emailSuffix): array
{
    $tenant = $tenantFixture['tenant'];
    $host = $tenantFixture['host'];
    $event = $tenantFixture['event'];
    $ticketType = $tenantFixture['ticketType'];
    $email = "reconcile-orders-{$emailSuffix}@example.com";

    $customer = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => $email,
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => $email,
        'password' => 'password',
    ])->json('access_token');

    $holdId = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            ]),
            $customer->id,
        )->id,
    );

    $orderId = test()->postJson('http://'.$host.'/v1/storefront/orders', [
        'hold_id' => $holdId,
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(201)->json('id');

    Auth::forgetGuards();

    $paymentId = test()->postJson('http://'.$host.'/v1/storefront/orders/'.$orderId.'/payments', [
        'method' => 'pix',
    ], ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => (string) Str::uuid7()])->assertStatus(201)->json('id');

    // A guard forgotten only after this fixture's own order call (mirroring
    // PaymentExpiryAndReconcileTest's own expiryFixture) leaves the
    // customer guard resolver caching this fixture's bearer token, so a
    // second call to this function for a different customer within the
    // same test would otherwise convert against the wrong customer id.
    // Forgetting again here keeps repeated calls independent.
    Auth::forgetGuards();

    return ['orderId' => $orderId, 'holdId' => $holdId, 'paymentId' => $paymentId];
}

// activity_log grants nodia_platform a DELETE restricted to rows past the
// retention pruner's own cutoff (stage-12 plan, task breakdown item 8),
// so entries this file records are never cleaned up between tests
// (mirroring ReplayFailedOutboxCommandTest's own posture); refusal cases
// assert a before/after count delta of zero rather than an absolute
// count, and every other assertion scopes by a unique operator name.
function reconcileOrdersActivityLogCount(): int
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => DB::table('activity_log')->where('event', 'payments_reconcile_orders_invoked')->count(),
    );
}

it('refuses to run without --operator', function (): void {
    $before = reconcileOrdersActivityLogCount();

    test()->artisan('payments:reconcile-orders --tenant=whatever --before=2026-07-13T12:00:00Z')
        ->assertFailed();

    expect(reconcileOrdersActivityLogCount())->toBe($before);
});

it('refuses to run fully unbounded, with no --order, --tenant, or --before at all', function (): void {
    $before = reconcileOrdersActivityLogCount();

    test()->artisan('payments:reconcile-orders --operator=refusal-unbounded')
        ->assertFailed();

    expect(reconcileOrdersActivityLogCount())->toBe($before);
});

it('refuses to run without --order or --tenant, even with --before given', function (): void {
    $before = reconcileOrdersActivityLogCount();

    test()->artisan('payments:reconcile-orders --operator=refusal-no-target --before=2026-07-13T12:00:00Z')
        ->assertFailed();

    expect(reconcileOrdersActivityLogCount())->toBe($before);
});

it('refuses to run without --before, even with --order given', function (): void {
    $before = reconcileOrdersActivityLogCount();

    test()->artisan('payments:reconcile-orders --operator=refusal-no-before --order='.Str::uuid7())
        ->assertFailed();

    expect(reconcileOrdersActivityLogCount())->toBe($before);
});

it('dry-run reports the named order without any side effect', function (): void {
    $tenantFixture = reconcileOrdersTenantFixture();
    $order = reconcileOrdersOrderFixture($tenantFixture, 'dry-run');

    $this->travelTo(CarbonImmutable::parse('2026-07-13T12:10:00Z'));

    test()->artisan(
        'payments:reconcile-orders --operator=dry-run-operator --order='.$order['orderId'].' --before=2026-07-13T12:10:00Z'
    )->assertSuccessful();

    [$payment, $orderModel] = app(TenantTransaction::class)->asTenant($tenantFixture['tenant']->id, fn (): array => [
        Payment::query()->findOrFail($order['paymentId']),
        Order::query()->findOrFail($order['orderId']),
    ]);

    expect($payment->status)->toBe(PaymentStatus::Initiated)
        ->and($orderModel->status)->toBe(OrderStatus::AwaitingPayment);

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'payments_reconcile_orders_invoked')
            ->where('properties->operator', 'dry-run-operator')
            ->latest('created_at')
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->properties['execute'])->toBeFalse()
        ->and($entry->properties['orders_matched'])->toBe(1)
        ->and($entry->properties['orders_resolved'])->toBe(0);
});

it('--execute polls exactly the named order and no others, transitioning through the state machine', function (): void {
    $tenantFixture = reconcileOrdersTenantFixture();
    $named = reconcileOrdersOrderFixture($tenantFixture, 'named');
    $other = reconcileOrdersOrderFixture($tenantFixture, 'other');

    app(FakeGatewayScenarios::class)->scriptQueryResult(
        'fake_'.$named['paymentId'],
        NormalizedPaymentEvent::confirmed('fake_'.$named['paymentId'], Money::of(250, 'USD')),
    );
    app(FakeGatewayScenarios::class)->scriptQueryResult(
        'fake_'.$other['paymentId'],
        NormalizedPaymentEvent::confirmed('fake_'.$other['paymentId'], Money::of(250, 'USD')),
    );

    $this->travelTo(CarbonImmutable::parse('2026-07-13T12:10:00Z'));

    test()->artisan(
        'payments:reconcile-orders --operator=execute-operator --execute --order='.$named['orderId'].' --before=2026-07-13T12:10:00Z'
    )->assertSuccessful();

    [$namedPayment, $namedOrder, $namedTicketCount] = app(TenantTransaction::class)->asTenant($tenantFixture['tenant']->id, fn (): array => [
        Payment::query()->findOrFail($named['paymentId']),
        Order::query()->findOrFail($named['orderId']),
        DB::table('tickets')->where('order_id', $named['orderId'])->count(),
    ]);

    expect($namedPayment->status)->toBe(PaymentStatus::Confirmed)
        ->and($namedOrder->status)->toBe(OrderStatus::Paid)
        ->and($namedTicketCount)->toBe(2);

    [$otherPayment, $otherOrder] = app(TenantTransaction::class)->asTenant($tenantFixture['tenant']->id, fn (): array => [
        Payment::query()->findOrFail($other['paymentId']),
        Order::query()->findOrFail($other['orderId']),
    ]);

    expect($otherPayment->status)->toBe(PaymentStatus::Initiated)
        ->and($otherOrder->status)->toBe(OrderStatus::AwaitingPayment);

    $entry = app(TenantTransaction::class)->asPlatform(
        fn () => ActivityLogEntry::query()
            ->where('event', 'payments_reconcile_orders_invoked')
            ->where('properties->operator', 'execute-operator')
            ->latest('created_at')
            ->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry->properties['execute'])->toBeTrue()
        ->and($entry->properties['orders_matched'])->toBe(1)
        ->and($entry->properties['orders_resolved'])->toBe(1);
});

it('bounded by --tenant reconciles every awaiting_payment order in that tenant, never another tenant\'s', function (): void {
    $tenantFixtureA = reconcileOrdersTenantFixture();
    $tenantFixtureB = reconcileOrdersTenantFixture();

    $orderA = reconcileOrdersOrderFixture($tenantFixtureA, 'tenant-a');
    $orderB = reconcileOrdersOrderFixture($tenantFixtureB, 'tenant-b');

    app(FakeGatewayScenarios::class)->scriptQueryResult(
        'fake_'.$orderA['paymentId'],
        NormalizedPaymentEvent::confirmed('fake_'.$orderA['paymentId'], Money::of(250, 'USD')),
    );
    app(FakeGatewayScenarios::class)->scriptQueryResult(
        'fake_'.$orderB['paymentId'],
        NormalizedPaymentEvent::confirmed('fake_'.$orderB['paymentId'], Money::of(250, 'USD')),
    );

    $this->travelTo(CarbonImmutable::parse('2026-07-13T12:10:00Z'));

    test()->artisan(
        'payments:reconcile-orders --operator=alice --execute --tenant='.$tenantFixtureA['tenant']->id.' --before=2026-07-13T12:10:00Z'
    )->assertSuccessful();

    $paymentA = app(TenantTransaction::class)->asTenant(
        $tenantFixtureA['tenant']->id,
        fn () => Payment::query()->findOrFail($orderA['paymentId']),
    );
    $paymentB = app(TenantTransaction::class)->asTenant(
        $tenantFixtureB['tenant']->id,
        fn () => Payment::query()->findOrFail($orderB['paymentId']),
    );

    expect($paymentA->status)->toBe(PaymentStatus::Confirmed)
        ->and($paymentB->status)->toBe(PaymentStatus::Initiated);
});
