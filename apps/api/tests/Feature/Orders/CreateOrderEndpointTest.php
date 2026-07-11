<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Models\Order;
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
 * Stage-07 plan, TDD sequencing Slice 1, task breakdown item 3: POST
 * /v1/storefront/orders, mirroring
 * tests/Feature/Inventory/HoldEndpointsTest.php's own Host-resolution
 * fixture pattern.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
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
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @return array{tenant: Tenant, host: string}
 */
function orderTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

/**
 * One published event with one priced, stocked GA ticket type.
 *
 * @return array{event: Event, ticketType: TicketType}
 */
function orderCatalog(string $tenantId, int $priceAmount = 2500, int $quantity = 10): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $priceAmount, $quantity): array {
        $event = Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]);
        $ticketType = TicketType::factory()->create([
            'tenant_id' => $tenantId,
            'event_id' => $event->id,
            'price' => Money::of($priceAmount, 'USD'),
        ]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'held' => 0,
            'sold' => 0,
        ]);

        return ['event' => $event, 'ticketType' => $ticketType];
    });
}

/**
 * @return array{customer: Customer, token: string}
 */
function orderCustomer(string $tenantId, string $host, string $email = 'buyer@example.com'): array
{
    $customer = app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => $email,
            'password' => 'password',
        ]),
    );

    $pair = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => $email,
        'password' => 'password',
    ])->json();

    return ['customer' => $customer, 'token' => $pair['access_token']];
}

function orderHold(string $tenantId, string $eventId, string $ticketTypeId, int $quantity = 2, ?string $customerId = null): string
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $eventId,
                'items' => [['ticket_type_id' => $ticketTypeId, 'quantity' => $quantity]],
            ]),
            $customerId,
        )->id,
    );
}

describe('POST /v1/storefront/orders', function (): void {
    it('converts a valid hold into a pending order with totals from ticket type prices', function (): void {
        ['tenant' => $tenant, 'host' => $host] = orderTenant();
        ['event' => $event, 'ticketType' => $ticketType] = orderCatalog($tenant->id, 2500);
        ['customer' => $customer, 'token' => $token] = orderCustomer($tenant->id, $host);
        $holdId = orderHold($tenant->id, $event->id, $ticketType->id, 2, $customer->id);

        $response = $this->postJson('http://'.$host.'/v1/storefront/orders', [
            'hold_id' => $holdId,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201)->assertConformsToOpenApi();

        $response->assertJsonPath('status', 'pending')
            ->assertJsonPath('event_id', $event->id)
            ->assertJsonPath('items.0.ticket_type_id', $ticketType->id)
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.unit_price.amount', 2500)
            ->assertJsonPath('subtotal.amount', 5000)
            ->assertJsonPath('discount.amount', 0)
            ->assertJsonPath('fees.amount', 0)
            ->assertJsonPath('total.amount', 5000)
            ->assertJsonPath('total.currency', 'USD')
            ->assertJsonPath('promo_code', null);

        expect(Str::isUuid($response->json('id')))->toBeTrue();

        // Inventory stays held: conversion commits nothing (system-design 7.1).
        $inventory = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketType->id)->firstOrFail(),
        );

        expect($inventory->held)->toBe(2)->and($inventory->sold)->toBe(0);
    });

    it('records OrderCreated to the outbox in the producing transaction with the full envelope', function (): void {
        ['tenant' => $tenant, 'host' => $host] = orderTenant();
        ['event' => $event, 'ticketType' => $ticketType] = orderCatalog($tenant->id, 2500);
        ['customer' => $customer, 'token' => $token] = orderCustomer($tenant->id, $host);
        $holdId = orderHold($tenant->id, $event->id, $ticketType->id, 2, $customer->id);

        $orderId = $this->postJson('http://'.$host.'/v1/storefront/orders', [
            'hold_id' => $holdId,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201)->json('id');

        $event = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => OutboxEvent::query()
                ->where('tenant_id', $tenant->id)
                ->where('type', 'OrderCreated')
                ->sole(),
        );

        expect($event->aggregate_type)->toBe('order')
            ->and($event->aggregate_id)->toBe($orderId)
            ->and($event->correlation_id)->not->toBeNull()
            ->and($event->payload['order_id'])->toBe($orderId)
            ->and($event->payload['customer_id'])->toBe($customer->id)
            ->and($event->payload['hold_id'])->toBe($holdId)
            ->and($event->payload['promo_code_id'])->toBeNull()
            ->and($event->payload['status'])->toBe('pending')
            ->and($event->payload['subtotal'])->toBe(['amount' => 5000, 'currency' => 'USD'])
            ->and($event->payload['discount'])->toBe(['amount' => 0, 'currency' => 'USD'])
            ->and($event->payload['fees'])->toBe(['amount' => 0, 'currency' => 'USD'])
            ->and($event->payload['total'])->toBe(['amount' => 5000, 'currency' => 'USD']);
    });

    it('copies attendee names onto the order items', function (): void {
        ['tenant' => $tenant, 'host' => $host] = orderTenant();
        ['event' => $event, 'ticketType' => $ticketType] = orderCatalog($tenant->id);
        ['customer' => $customer, 'token' => $token] = orderCustomer($tenant->id, $host);
        $holdId = orderHold($tenant->id, $event->id, $ticketType->id, 2, $customer->id);

        $response = $this->postJson('http://'.$host.'/v1/storefront/orders', [
            'hold_id' => $holdId,
            'attendee_names' => [$ticketType->id => ['Ada Lovelace', 'Grace Hopper']],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201)
            ->assertJsonPath('items.0.attendee_names', ['Ada Lovelace', 'Grace Hopper']);
    });

    it('attaches an anonymous hold to the authenticated customer', function (): void {
        ['tenant' => $tenant, 'host' => $host] = orderTenant();
        ['event' => $event, 'ticketType' => $ticketType] = orderCatalog($tenant->id);
        ['customer' => $customer, 'token' => $token] = orderCustomer($tenant->id, $host);
        $holdId = orderHold($tenant->id, $event->id, $ticketType->id, 2, null);

        $response = $this->postJson('http://'.$host.'/v1/storefront/orders', [
            'hold_id' => $holdId,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);

        $holdCustomerId = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => DB::table('holds')->where('id', $holdId)->value('customer_id'),
        );
        $order = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Order::query()->findOrFail($response->json('id')),
        );

        expect($holdCustomerId)->toBe($customer->id)
            ->and($order->customer_id)->toBe($customer->id);
    });

    it('renders hold_not_found for a hold owned by a different customer', function (): void {
        ['tenant' => $tenant, 'host' => $host] = orderTenant();
        ['event' => $event, 'ticketType' => $ticketType] = orderCatalog($tenant->id);
        ['customer' => $owner] = orderCustomer($tenant->id, $host, 'owner@example.com');
        ['token' => $token] = orderCustomer($tenant->id, $host, 'thief@example.com');
        $holdId = orderHold($tenant->id, $event->id, $ticketType->id, 2, $owner->id);

        $response = $this->postJson('http://'.$host.'/v1/storefront/orders', [
            'hold_id' => $holdId,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(404)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'hold_not_found');
    });

    it('renders hold_not_found for an unknown hold id', function (): void {
        ['tenant' => $tenant, 'host' => $host] = orderTenant();
        orderCatalog($tenant->id);
        ['token' => $token] = orderCustomer($tenant->id, $host);

        $response = $this->postJson('http://'.$host.'/v1/storefront/orders', [
            'hold_id' => Str::uuid7()->toString(),
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(404)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'hold_not_found');
    });

    it('renders checkout.hold_expired for an expired hold even when the sweeper lags', function (): void {
        ['tenant' => $tenant, 'host' => $host] = orderTenant();
        ['event' => $event, 'ticketType' => $ticketType] = orderCatalog($tenant->id);
        ['customer' => $customer, 'token' => $token] = orderCustomer($tenant->id, $host);

        $now = now();
        $this->travelTo($now);
        $holdId = orderHold($tenant->id, $event->id, $ticketType->id, 2, $customer->id);

        // Past expires_at, sweeper never runs: the hold row still says active.
        $this->travelTo($now->copy()->addMinutes(11));

        $response = $this->postJson('http://'.$host.'/v1/storefront/orders', [
            'hold_id' => $holdId,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(409)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'checkout.hold_expired');

        $orders = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Order::query()->where('hold_id', $holdId)->count(),
        );

        expect($orders)->toBe(0);
    });

    it('renders hold_already_converted when an order already references the hold', function (): void {
        ['tenant' => $tenant, 'host' => $host] = orderTenant();
        ['event' => $event, 'ticketType' => $ticketType] = orderCatalog($tenant->id);
        ['customer' => $customer, 'token' => $token] = orderCustomer($tenant->id, $host);
        $holdId = orderHold($tenant->id, $event->id, $ticketType->id, 2, $customer->id);

        $this->postJson('http://'.$host.'/v1/storefront/orders', [
            'hold_id' => $holdId,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $response = $this->postJson('http://'.$host.'/v1/storefront/orders', [
            'hold_id' => $holdId,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(409)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'hold_already_converted');
    });

    it('requires a customer bearer token', function (): void {
        ['tenant' => $tenant, 'host' => $host] = orderTenant();
        ['event' => $event, 'ticketType' => $ticketType] = orderCatalog($tenant->id);
        $holdId = orderHold($tenant->id, $event->id, $ticketType->id);

        $response = $this->postJson('http://'.$host.'/v1/storefront/orders', [
            'hold_id' => $holdId,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('renders request.validation_failed without a hold_id', function (): void {
        ['tenant' => $tenant, 'host' => $host] = orderTenant();
        ['token' => $token] = orderCustomer($tenant->id, $host);

        $response = $this->postJson('http://'.$host.'/v1/storefront/orders', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'request.validation_failed');
    });
});
