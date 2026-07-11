<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Orders\Enums\OrderStatus;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08a plan, Slice 3: GET /v1/storefront/orders/{order}/payment-methods.
 * The offer is the union of methods from the tenant's enabled gateways
 * whose capability flags cover the order currency, minus slow methods
 * excluded by the event policy or the automatic low-inventory cutoff.
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
            DB::table('payments')->where('tenant_id', $tenantId)->delete();
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
 * A tenant with configurable gateways, a published event with
 * configurable inventory and async policy, and a pending order created
 * over the real conversion endpoint.
 *
 * @return array{host: string, tenantId: string, token: string, orderId: string}
 */
function offerFixture(
    array $enabledGateways = ['fake'],
    int $quantity = 100,
    ?array $asyncPaymentPolicy = null,
    string $settlementCurrency = 'USD',
): array {
    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function () use ($enabledGateways, $settlementCurrency): array {
        $tenant = Tenant::factory()->create([
            'enabled_gateways' => $enabledGateways,
            'settlement_currency' => $settlementCurrency,
        ]);
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });

    ['event' => $event, 'ticketType' => $ticketType] = app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant, $quantity, $asyncPaymentPolicy, $settlementCurrency): array {
        $event = Event::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => EventStatus::Published,
            ...($asyncPaymentPolicy === null ? [] : ['async_payment_policy' => $asyncPaymentPolicy]),
        ]);
        $ticketType = TicketType::factory()->create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'currency' => $settlementCurrency,
        ]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenant->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'held' => 0,
            'sold' => 0,
        ]);

        return ['event' => $event, 'ticketType' => $ticketType];
    });

    $customer = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'offer-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'offer-buyer@example.com',
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

function getOffer(array $fixture)
{
    // The fixture's own conversion request cached the buyer on the
    // customer guard; drop it so this request authenticates its own
    // bearer token.
    Auth::forgetGuards();

    return test()->getJson(
        'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payment-methods',
        ['Authorization' => 'Bearer '.$fixture['token']],
    );
}

describe('GET /v1/storefront/orders/{order}/payment-methods', function (): void {
    it('offers every method of the enabled gateway covering the order currency', function (): void {
        $fixture = offerFixture();

        $response = getOffer($fixture);

        $response->assertStatus(200)->assertConformsToOpenApi();

        $methods = collect($response->json('data'))->keyBy('method');

        expect($methods)->toHaveCount(3)
            ->and($methods['card']['gateway'])->toBe('fake')
            ->and($methods['card']['confirmation'])->toBe('sync')
            ->and($methods['card']['confirmation_window_minutes'])->toBeNull()
            ->and($methods['pix']['confirmation'])->toBe('async')
            ->and($methods['pix']['confirmation_window_minutes'])->toBe(30)
            ->and($methods['boleto']['confirmation_window_minutes'])->toBe(4320);
    });

    it('returns an empty offer when the tenant enables no gateway', function (): void {
        $response = getOffer(offerFixture(enabledGateways: []));

        $response->assertStatus(200)->assertConformsToOpenApi();
        expect($response->json('data'))->toBe([]);
    });

    it('ignores enabled gateway identifiers with no registered adapter', function (): void {
        $response = getOffer(offerFixture(enabledGateways: ['stripe', 'fake']));

        $response->assertStatus(200);
        expect(collect($response->json('data'))->pluck('gateway')->unique()->all())->toBe(['fake']);
    });

    it('returns an empty offer when no gateway covers the order currency', function (): void {
        config()->set('payments.gateways.fake.currencies', ['BRL']);

        $response = getOffer(offerFixture());

        $response->assertStatus(200)->assertConformsToOpenApi();
        expect($response->json('data'))->toBe([]);
    });

    it('excludes slow methods when the event policy disables them', function (): void {
        $response = getOffer(offerFixture(asyncPaymentPolicy: ['slow_methods_enabled' => false, 'low_inventory_cutoff' => null]));

        $response->assertStatus(200);
        expect(collect($response->json('data'))->pluck('method')->all())->toBe(['card']);
    });

    it('excludes slow methods automatically when remaining inventory reaches the platform cutoff', function (): void {
        // quantity 12 minus the 2 held by this order leaves 10, the
        // config default cutoff, so async methods drop out.
        $response = getOffer(offerFixture(quantity: 12));

        $response->assertStatus(200);
        expect(collect($response->json('data'))->pluck('method')->all())->toBe(['card']);
    });

    it('honors the per-event low inventory cutoff override', function (): void {
        $response = getOffer(offerFixture(quantity: 12, asyncPaymentPolicy: ['slow_methods_enabled' => true, 'low_inventory_cutoff' => 5]));

        $response->assertStatus(200);
        expect(collect($response->json('data'))->pluck('method')->sort()->values()->all())->toBe(['boleto', 'card', 'pix']);
    });

    it('renders order_not_payable when the order is not pending', function (): void {
        $fixture = offerFixture();

        app(TenantTransaction::class)->asTenant($fixture['tenantId'], function () use ($fixture): void {
            DB::table('orders')->where('id', $fixture['orderId'])->update(['status' => OrderStatus::AwaitingPayment->value]);
        });

        $response = getOffer($fixture);

        $response->assertStatus(409)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'order_not_payable');
    });

    it('renders request.not_found for another customer\'s order', function (): void {
        $fixture = offerFixture();

        app(TenantTransaction::class)->asTenant(
            $fixture['tenantId'],
            fn () => Customer::factory()->create([
                'tenant_id' => $fixture['tenantId'],
                'email' => 'other-offer-buyer@example.com',
                'password' => 'password',
            ]),
        );

        $otherToken = test()->postJson('http://'.$fixture['host'].'/v1/auth/customer/token', [
            'email' => 'other-offer-buyer@example.com',
            'password' => 'password',
        ])->json('access_token');

        Auth::forgetGuards();

        $response = test()->getJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payment-methods',
            ['Authorization' => 'Bearer '.$otherToken],
        );

        $response->assertStatus(404)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'request.not_found');
    });

    it('requires a customer bearer token', function (): void {
        $fixture = offerFixture();

        Auth::forgetGuards();

        test()->getJson('http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payment-methods')
            ->assertStatus(401);
    });
});
