<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Capability;
use App\Inventory\Actions\ReleaseExpiredHolds;
use App\Inventory\Models\TicketTypeInventory;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-06 plan, TDD sequencing Slice 3, task breakdown item 7: the
 * storefront availability read and the admin ticket-type inventory read,
 * mirroring tests/Feature/Inventory/HoldEndpointsTest.php's own
 * Host-resolution fixture pattern for the storefront half and
 * tests/Feature/EventCatalog/TicketTypeEndpointsTest.php's own staff
 * bearer plus X-Tenant-Id pattern for the admin half.
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
            DB::table('hold_items')->where('tenant_id', $tenantId)->delete();
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

/**
 * @return array{tenant: Tenant, host: string}
 */
function availabilityTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function availabilityEvent(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $ticketTypeAttributes
 */
function availabilityTicketType(string $tenantId, string $eventId, int $quantity, int $sold = 0, int $held = 0, array $ticketTypeAttributes = []): TicketType
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $quantity, $sold, $held, $ticketTypeAttributes): TicketType {
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $eventId, ...$ticketTypeAttributes]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'sold' => $sold,
            'held' => $held,
        ]);

        return $ticketType;
    });
}

describe('GET /v1/storefront/events/{event}/availability', function (): void {
    it('returns available and on_sale per ticket type', function (): void {
        ['tenant' => $tenant, 'host' => $host] = availabilityTenant();
        $event = availabilityEvent($tenant->id);
        $ticketType = availabilityTicketType($tenant->id, $event->id, quantity: 10, sold: 3, held: 2);

        $response = $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/availability');

        $response->assertStatus(200)->assertConformsToOpenApi();

        $response->assertJsonPath('event_id', $event->id)
            ->assertJsonPath('ticket_types.0.ticket_type_id', $ticketType->id)
            ->assertJsonPath('ticket_types.0.available', 5)
            ->assertJsonPath('ticket_types.0.on_sale', true);
    });

    it('reports on_sale false outside the sales window', function (): void {
        ['tenant' => $tenant, 'host' => $host] = availabilityTenant();
        $event = availabilityEvent($tenant->id);
        $ticketType = availabilityTicketType($tenant->id, $event->id, quantity: 10, ticketTypeAttributes: [
            'sales_start' => now()->addDay(),
            'sales_end' => now()->addDays(2),
        ]);

        $response = $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/availability');

        $response->assertStatus(200)
            ->assertJsonPath('ticket_types.0.ticket_type_id', $ticketType->id)
            ->assertJsonPath('ticket_types.0.on_sale', false);
    });

    it('returns event_not_found for an unknown event', function (): void {
        ['host' => $host] = availabilityTenant();

        $this->getJson('http://'.$host.'/v1/storefront/events/'.Str::uuid7().'/availability')
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'event_not_found');
    });

    it('returns event_not_found for a draft (unpublished) event', function (): void {
        ['tenant' => $tenant, 'host' => $host] = availabilityTenant();
        $event = availabilityEvent($tenant->id, ['status' => EventStatus::Draft]);

        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/availability')
            ->assertStatus(404)
            ->assertJsonPath('code', 'event_not_found');
    });

    it('recovers availability exactly after a hold expires', function (): void {
        ['tenant' => $tenant, 'host' => $host] = availabilityTenant();
        $event = availabilityEvent($tenant->id);
        $ticketType = availabilityTicketType($tenant->id, $event->id, quantity: 10);

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 4]],
        ])->json();

        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/availability')
            ->assertJsonPath('ticket_types.0.available', 6);

        $this->travelTo(now()->addMinutes(11));
        app(ReleaseExpiredHolds::class)();

        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/availability')
            ->assertJsonPath('ticket_types.0.available', 10);

        expect($created['id'])->not->toBeNull();
    });
});

describe('GET /v1/ticket-types/{ticket_type}/inventory', function (): void {
    it('returns quantity, sold, held for a staff bearer with X-Tenant-Id', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        $event = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]),
        );
        $ticketType = availabilityTicketType($tenant->id, $event->id, quantity: 8, sold: 1, held: 2);

        $response = $this->getJson('/v1/ticket-types/'.$ticketType->id.'/inventory', [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsView]),
            'X-Tenant-Id' => $tenant->id,
        ]);

        $response->assertStatus(200)->assertConformsToOpenApi();

        $response->assertJsonPath('quantity', 8)
            ->assertJsonPath('sold', 1)
            ->assertJsonPath('held', 2);
    });

    it('returns request.not_found for an unknown ticket type', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());

        $this->getJson('/v1/ticket-types/'.Str::uuid7().'/inventory', [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsView]),
            'X-Tenant-Id' => $tenant->id,
        ])
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns request.not_found for a cross-tenant ticket type', function (): void {
        $tenantA = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        $tenantB = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());

        $eventA = app(TenantTransaction::class)->asTenant(
            $tenantA->id,
            fn () => Event::factory()->create(['tenant_id' => $tenantA->id, 'status' => EventStatus::Published]),
        );
        $ticketType = availabilityTicketType($tenantA->id, $eventA->id, quantity: 5);

        $this->getJson('/v1/ticket-types/'.$ticketType->id.'/inventory', [
            'Authorization' => 'Bearer '.TenantStaff::token($tenantB->id, [Capability::EventsView]),
            'X-Tenant-Id' => $tenantB->id,
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'request.not_found');
    });
});
