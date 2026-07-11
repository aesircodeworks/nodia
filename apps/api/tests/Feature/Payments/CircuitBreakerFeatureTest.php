<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Models\TicketTypeInventory;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\FakeGatewayScenarios;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Support\CircuitBreaker;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-08a plan, Slice 8: with the breaker open, the gateway's methods
 * disappear from the offer and initiation returns 503 with Retry-After,
 * while other gateways' methods remain offered (system-design 13: never
 * degrade the whole checkout).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Cache::flush();

    // A second registered adapter so exclusion can be proven per gateway.
    $this->app->scoped(GatewayRegistry::class, fn ($app) => new GatewayRegistry([
        'fake' => $app->make(FakeGateway::class),
        'fake2' => new FakeGateway($app->make(FakeGatewayScenarios::class), 'fake2'),
    ]));
});

afterEach(function (): void {
    Cache::flush();

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
 * @return array{host: string, tenantId: string, token: string, orderId: string}
 */
function breakerFixture(): array
{
    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create(['enabled_gateways' => ['fake', 'fake2']]);
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
            'email' => 'breaker-buyer@example.com',
            'password' => 'password',
        ]),
    );

    $token = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'breaker-buyer@example.com',
        'password' => 'password',
    ])->json('access_token');

    $holdId = app(TenantTransaction::class)->asTenant(
        $tenant->id,
        fn () => app(CreateHold::class)(
            CreateHoldData::from([
                'event_id' => $event->id,
                'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            ]),
            $customer->id,
        )->id,
    );

    $orderId = test()->postJson('http://'.$host.'/v1/storefront/orders', [
        'hold_id' => $holdId,
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(201)->json('id');

    Auth::forgetGuards();

    return ['host' => $host, 'tenantId' => $tenant->id, 'token' => $token, 'orderId' => $orderId];
}

function openBreaker(string $gateway): void
{
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, (int) config('payments.circuit_breaker.failure_threshold')) as $ignored) {
        $breaker->recordFailure($gateway);
    }
}

describe('circuit breaker over HTTP', function (): void {
    it('drops an open gateway from the offer while the other gateway remains', function (): void {
        $fixture = breakerFixture();

        openBreaker('fake');

        $response = test()->getJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payment-methods',
            ['Authorization' => 'Bearer '.$fixture['token']],
        );

        $response->assertStatus(200);
        expect(collect($response->json('data'))->pluck('gateway')->unique()->values()->all())->toBe(['fake2']);
    });

    it('returns 503 with Retry-After when initiating against an open breaker', function (): void {
        $fixture = breakerFixture();

        openBreaker('fake');

        $response = test()->postJson(
            'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payments',
            ['method' => 'card', 'details' => ['token' => 'tok_approve']],
            ['Authorization' => 'Bearer '.$fixture['token'], 'Idempotency-Key' => (string) Str::uuid7()],
        );

        $response->assertStatus(503)->assertConformsToOpenApi();
        $response->assertJsonPath('code', 'gateway_unavailable');
        expect($response->headers->get('Retry-After'))->not->toBeNull();
    });

    it('opens the breaker from repeated transport failures on the initiation path', function (): void {
        $fixture = breakerFixture();

        config()->set('payments.circuit_breaker.failure_threshold', 2);

        app(FakeGatewayScenarios::class)->failNextCreate(2);

        foreach (range(1, 2) as $ignored) {
            test()->postJson(
                'http://'.$fixture['host'].'/v1/storefront/orders/'.$fixture['orderId'].'/payments',
                ['method' => 'card', 'details' => ['token' => 'tok_approve']],
                ['Authorization' => 'Bearer '.$fixture['token'], 'Idempotency-Key' => (string) Str::uuid7()],
            )->assertStatus(503);
        }

        expect(app(CircuitBreaker::class)->isOpen('fake'))->toBeTrue();
    });
});
