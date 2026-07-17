<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Payments\Enums\SubmerchantStatus;
use App\Payments\Models\SubmerchantAccount;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08c plan, Slice 4: a split-support gateway's methods are
 * offered only when the tenant's sub-merchant account on that gateway
 * is active (system-design 7.3); initiation against a non-active
 * gateway fails distinctly (409 submerchant_not_active) from a method
 * that was never offered at all (422 payment_method_not_available).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    config()->set('payments.gateways.fake.split_support', true);
});

afterEach(function (): void {
    config()->set('payments.gateways.fake.split_support', false);

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
            DB::table('submerchant_accounts')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * A tenant enabling the fake gateway (split support turned on for this
 * suite) with a published event, inventory, and a pending order created
 * over the real conversion endpoint.
 *
 * @return array{host: string, tenantId: string, token: string, orderId: string}
 */
function submerchantGatingFixture(): array
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

    $customer = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'submerchant-gating-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'submerchant-gating-buyer@example.com',
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

function submerchantAccountFor(string $tenantId, SubmerchantStatus $status): SubmerchantAccount
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => SubmerchantAccount::factory()->create([
            'tenant_id' => $tenantId,
            'gateway' => 'fake',
            'status' => $status,
        ]),
    );
}

function getSubmerchantOffer(array $fixture)
{
    Auth::forgetGuards();

    return test()->getJson(
        'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payment-methods',
        ['Authorization' => 'Bearer '.$fixture['token']],
    );
}

function initiateSubmerchantPayment(array $fixture, string $method = 'card')
{
    Auth::forgetGuards();

    return test()->postJson(
        'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payments',
        ['method' => $method, 'details' => ['token' => 'tok_approve']],
        ['Authorization' => 'Bearer '.$fixture['token'], 'Idempotency-Key' => (string) Str::uuid()],
    );
}

describe('checkout offer gating on sub-merchant status', function (): void {
    it('excludes the gateway when no sub-merchant account exists', function (): void {
        $fixture = submerchantGatingFixture();

        $response = getSubmerchantOffer($fixture);

        $response->assertStatus(200)->assertConformsToOpenApi();
        expect($response->json('data'))->toBe([]);
    });

    it('excludes the gateway while the sub-merchant is pending', function (): void {
        $fixture = submerchantGatingFixture();
        submerchantAccountFor($fixture['tenantId'], SubmerchantStatus::Pending);

        $response = getSubmerchantOffer($fixture);

        $response->assertStatus(200)->assertConformsToOpenApi();
        expect($response->json('data'))->toBe([]);
    });

    it('includes the gateway once the sub-merchant is active', function (): void {
        $fixture = submerchantGatingFixture();
        submerchantAccountFor($fixture['tenantId'], SubmerchantStatus::Active);

        $response = getSubmerchantOffer($fixture);

        $response->assertStatus(200)->assertConformsToOpenApi();
        expect(collect($response->json('data'))->pluck('gateway')->unique()->all())->toBe(['fake']);
    });

    it('excludes the gateway again once the sub-merchant is disabled', function (): void {
        $fixture = submerchantGatingFixture();
        submerchantAccountFor($fixture['tenantId'], SubmerchantStatus::Disabled);

        $response = getSubmerchantOffer($fixture);

        $response->assertStatus(200)->assertConformsToOpenApi();
        expect($response->json('data'))->toBe([]);
    });
});

describe('POST /v1/storefront/orders/{order}/payments sub-merchant gating', function (): void {
    it('renders submerchant_not_active when the gateway has no active sub-merchant', function (): void {
        $fixture = submerchantGatingFixture();
        submerchantAccountFor($fixture['tenantId'], SubmerchantStatus::Pending);

        $response = initiateSubmerchantPayment($fixture);

        $response->assertStatus(409)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'submerchant_not_active');
    });

    it('initiates normally once the sub-merchant is active', function (): void {
        $fixture = submerchantGatingFixture();
        submerchantAccountFor($fixture['tenantId'], SubmerchantStatus::Active);

        $response = initiateSubmerchantPayment($fixture);

        $response->assertStatus(201)->assertConformsToOpenApi();
    });
});
