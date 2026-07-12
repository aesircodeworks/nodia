<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08d plan, Slice 3: the skeleton is registered behind an
 * environment flag; a tenant that enables it sees no methods from it in
 * the checkout offer, and the offer is otherwise unchanged from the
 * gateway not being enabled at all. Initiation naming a method whose
 * only enabled gateway resolves to the skeleton fails distinctly (409
 * gateway_not_configured) from a method genuinely outside the offer
 * (422 payment_method_not_available), and distinct from an open circuit
 * breaker (503 gateway_unavailable).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    config(['payments.gateways.pending.enabled' => false]);
    app()->forgetScopedInstances();

    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('payments')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
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
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * A tenant enabling only the given gateways, with a published event,
 * inventory, and a pending order created over the real conversion
 * endpoint.
 *
 * @param  list<string>  $enabledGateways
 * @return array{host: string, tenantId: string, token: string, orderId: string}
 */
function pendingGatewayFixture(array $enabledGateways): array
{
    Auth::forgetGuards();

    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function () use ($enabledGateways): array {
        $tenant = Tenant::factory()->create(['enabled_gateways' => $enabledGateways]);
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

    $customer = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'pending-gateway-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'pending-gateway-buyer@example.com',
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

    return ['host' => $host, 'tenantId' => $tenant->id, 'token' => $token, 'orderId' => $orderId];
}

function getPendingGatewayOffer(array $fixture)
{
    Auth::forgetGuards();

    return test()->getJson(
        'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payment-methods',
        ['Authorization' => 'Bearer '.$fixture['token']],
    );
}

function initiatePendingGatewayPayment(array $fixture, string $method = 'card')
{
    Auth::forgetGuards();

    return test()->postJson(
        'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payments',
        ['method' => $method, 'details' => ['token' => 'tok_approve']],
        ['Authorization' => 'Bearer '.$fixture['token'], 'Idempotency-Key' => (string) Str::uuid()],
    );
}

describe('checkout offer with the pending gateway skeleton registered', function (): void {
    it('contains no methods from the pending gateway, offering exactly what the other enabled gateway offers alone', function (): void {
        config(['payments.gateways.pending.enabled' => false]);
        app()->forgetScopedInstances();
        $baseline = pendingGatewayFixture(['fake']);
        $offerWithoutPending = getPendingGatewayOffer($baseline);
        $offerWithoutPending->assertStatus(200)->assertConformsToOpenApi();

        config(['payments.gateways.pending.enabled' => true]);
        app()->forgetScopedInstances();
        $withPending = pendingGatewayFixture(['fake', 'pending']);
        $offerWithPending = getPendingGatewayOffer($withPending);
        $offerWithPending->assertStatus(200)->assertConformsToOpenApi();

        $methodsOf = fn (array $offer): array => collect($offer)
            ->map(fn (array $item): array => array_diff_key($item, array_flip(['payment_id'])))
            ->all();

        expect(collect($offerWithPending->json('data'))->pluck('gateway')->unique()->all())->toBe(['fake'])
            ->and($methodsOf($offerWithPending->json('data')))->toBe($methodsOf($offerWithoutPending->json('data')));
    });
});

describe('POST /v1/storefront/orders/{order}/payments naming the pending gateway', function (): void {
    it('renders gateway_not_configured, distinct from gateway_unavailable, when the only enabled gateway is the skeleton', function (): void {
        config(['payments.gateways.pending.enabled' => true]);
        app()->forgetScopedInstances();
        $fixture = pendingGatewayFixture(['pending']);

        $response = initiatePendingGatewayPayment($fixture);

        $response->assertStatus(409)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'gateway_not_configured');
    });
});
