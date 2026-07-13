<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\TicketType;
use App\EventCatalog\Models\Venue;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\TicketTypeInventory;
use App\Inventory\Support\ReadCache;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-10 plan, TDD sequencing Slice 7 (Feature, first, mandated), task
 * breakdown item 10: the clock-aware Redis read cache fronting GET
 * /v1/storefront/events/{event}/availability and .../seats. Mirrors
 * tests/Feature/Inventory/AvailabilityEndpointsTest.php's and
 * tests/Feature/Inventory/EventSeatEndpointsTest.php's own fixture
 * style; global helper names are namespaced readCache* to avoid
 * collisions with those files' own same-purpose helpers (Pest loads
 * every test file's top-level functions into one process).
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
            DB::table('event_seats')->where('tenant_id', $tenantId)->delete();
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
            DB::table('seats')->where('tenant_id', $tenantId)->delete();
            DB::table('seat_maps')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
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
function readCacheTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

function readCacheEvent(string $tenantId): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published]),
    );
}

function readCacheTicketType(string $tenantId, string $eventId, int $quantity): TicketType
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $quantity): TicketType {
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $eventId]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'held' => 0,
            'sold' => 0,
        ]);

        return $ticketType;
    });
}

/**
 * @return array{event: Event, ticketType: TicketType, seatIds: list<string>}
 */
function readCacheSeatedEvent(string $tenantId, int $seatCount): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $seatCount): array {
        $venue = Venue::factory()->create(['tenant_id' => $tenantId]);
        $seatMap = SeatMap::factory()->create(['tenant_id' => $tenantId, 'venue_id' => $venue->id]);
        $event = Event::factory()->create([
            'tenant_id' => $tenantId,
            'status' => EventStatus::Published,
            'venue_id' => $venue->id,
            'seat_map_id' => $seatMap->id,
            'is_virtual' => false,
            'virtual_event_url' => null,
        ]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id, 'requires_seat' => true]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $seatCount,
            'held' => 0,
            'sold' => 0,
        ]);

        $seatIds = [];

        for ($i = 0; $i < $seatCount; $i++) {
            $templateSeat = Seat::factory()->create(['tenant_id' => $tenantId, 'seat_map_id' => $seatMap->id, 'section' => 'A', 'row' => '1', 'number' => (string) ($i + 1)]);

            $eventSeat = EventSeat::factory()->create([
                'tenant_id' => $tenantId,
                'event_id' => $event->id,
                'seat_id' => $templateSeat->id,
                'ticket_type_id' => $ticketType->id,
                'status' => EventSeatStatus::Available,
            ]);

            $seatIds[] = $eventSeat->id;
        }

        return ['event' => $event, 'ticketType' => $ticketType, 'seatIds' => $seatIds];
    });
}

describe('GET /v1/storefront/events/{event}/availability cache convergence', function (): void {
    it('serves the stale cached value inside the TTL after PostgreSQL changes, then matches PostgreSQL exactly once the ttl elapses', function (): void {
        $ttl = config()->integer('onsale.cache.availability_ttl_seconds');

        ['tenant' => $tenant, 'host' => $host] = readCacheTenant();
        $event = readCacheEvent($tenant->id);
        $ticketType = readCacheTicketType($tenant->id, $event->id, quantity: 10);

        $this->travelTo(now());

        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/availability')
            ->assertStatus(200)
            ->assertJsonPath('ticket_types.0.available', 10);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 4]],
        ])->assertStatus(201);

        // Still inside the TTL, same frozen instant: the cache serves the
        // stale pre-hold value even though PostgreSQL already changed.
        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/availability')
            ->assertStatus(200)
            ->assertJsonPath('ticket_types.0.available', 10);

        $this->travelTo(now()->addSeconds($ttl + 1));

        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/availability')
            ->assertStatus(200)
            ->assertJsonPath('ticket_types.0.available', 6);
    });
});

describe('GET /v1/storefront/events/{event}/seats cache convergence', function (): void {
    it('serves the stale cached status collapse inside the TTL, then matches PostgreSQL exactly once the ttl elapses', function (): void {
        $ttl = config()->integer('onsale.cache.seats_ttl_seconds');

        ['tenant' => $tenant, 'host' => $host] = readCacheTenant();
        ['event' => $event, 'seatIds' => $seatIds] = readCacheSeatedEvent($tenant->id, 3);

        $this->travelTo(now());

        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/seats')
            ->assertStatus(200)
            ->assertJsonPath('seats.0.status', 'available');

        app(TenantTransaction::class)->asTenant($tenant->id, function () use ($seatIds): void {
            EventSeat::query()->whereKey($seatIds[0])->update(['status' => EventSeatStatus::Held->value]);
        });

        // Still inside the TTL, same frozen instant: the cache serves the
        // stale pre-hold seat map even though PostgreSQL already changed.
        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/seats')
            ->assertStatus(200)
            ->assertJsonPath('seats.0.status', 'available');

        $this->travelTo(now()->addSeconds($ttl + 1));

        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/seats')
            ->assertStatus(200)
            ->assertJsonPath('seats.0.status', 'unavailable');
    });
});

describe('the read cache is never authoritative', function (): void {
    it('a poisoned availability cache entry cannot make hold creation bypass the real counter guard', function (): void {
        ['tenant' => $tenant, 'host' => $host] = readCacheTenant();
        $event = readCacheEvent($tenant->id);
        $ticketType = readCacheTicketType($tenant->id, $event->id, quantity: 2);

        $now = now();
        $this->travelTo($now);

        ReadCache::put(
            ReadCache::key($tenant->id, $event->id, 'availability'),
            ['event_id' => $event->id, 'ticket_types' => [['ticket_type_id' => $ticketType->id, 'available' => 999, 'on_sale' => true]]],
            $now,
            3600,
        );

        // The poisoned entry is what the read endpoint now serves...
        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/availability')
            ->assertStatus(200)
            ->assertJsonPath('ticket_types.0.available', 999);

        // ...but CreateHold never reads this cache: its own conditional
        // UPDATE against ticket_type_inventory is the sole source of
        // truth, so a request for more than the real quantity (2) still
        // fails on the real guard, not the poisoned 999.
        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 5]],
        ])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'insufficient_inventory');
    });
});
