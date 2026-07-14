<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\TicketType;
use App\EventCatalog\Models\Venue;
use App\Identity\Capability;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
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
 * Stage-06 plan, TDD sequencing Slice 7, task breakdown item 11: the
 * storefront seat read, the admin seats list, and the admin PATCH seat
 * operations, mirroring tests/Feature/Inventory/HoldEndpointsTest.php's
 * own holdSeatedTicketType fixture for materialized event_seats and
 * tests/Feature/Inventory/AvailabilityEndpointsTest.php's own staff
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
            DB::table('event_seats')->where('tenant_id', $tenantId)->delete();
            DB::table('hold_items')->where('tenant_id', $tenantId)->delete();
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
            DB::table('seats')->where('tenant_id', $tenantId)->delete();
            DB::table('seat_maps')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
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
function seatsTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

/**
 * A published, seated event with `$seatCount` materialized seats zoned to
 * one requires_seat ticket type, mirroring
 * tests/Feature/Inventory/HoldEndpointsTest.php's own
 * holdSeatedTicketType fixture.
 *
 * @return array{event: Event, ticketType: TicketType, seatIds: list<string>, seatMapSeatIds: array<string, string>}
 */
function seatedEventFixture(string $tenantId, int $seatCount): array
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
        $seatMapSeatIds = [];

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
            $seatMapSeatIds[$eventSeat->id] = $templateSeat->id;
        }

        return ['event' => $event, 'ticketType' => $ticketType, 'seatIds' => $seatIds, 'seatMapSeatIds' => $seatMapSeatIds];
    });
}

describe('GET /v1/storefront/events/{event}/seats', function (): void {
    it('returns per-seat metadata with held and sold collapsed to unavailable', function (): void {
        ['tenant' => $tenant, 'host' => $host] = seatsTenant();
        ['event' => $event, 'seatIds' => $seatIds] = seatedEventFixture($tenant->id, 3);

        app(TenantTransaction::class)->asTenant($tenant->id, function () use ($seatIds): void {
            EventSeat::query()->whereKey($seatIds[0])->update(['status' => EventSeatStatus::Held->value]);
            EventSeat::query()->whereKey($seatIds[1])->update(['status' => EventSeatStatus::Sold->value]);
        });

        $response = $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/seats');

        $response->assertStatus(200)->assertConformsToOpenApi();

        $response->assertJsonPath('event_id', $event->id)
            ->assertJsonCount(3, 'seats')
            ->assertJsonPath('seats.0.status', 'unavailable')
            ->assertJsonPath('seats.1.status', 'unavailable')
            ->assertJsonPath('seats.2.status', 'available')
            ->assertJsonPath('seats.2.section', 'A')
            ->assertJsonPath('seats.2.row', '1');
    });

    it('returns event_not_found for an unknown event', function (): void {
        ['host' => $host] = seatsTenant();

        $this->getJson('http://'.$host.'/v1/storefront/events/'.Str::uuid7().'/seats')
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'event_not_found');
    });

    it('returns event_not_seated for a GA-only event', function (): void {
        ['tenant' => $tenant, 'host' => $host] = seatsTenant();
        $event = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Event::factory()->create(['tenant_id' => $tenant->id, 'status' => EventStatus::Published]),
        );

        $this->getJson('http://'.$host.'/v1/storefront/events/'.$event->id.'/seats')
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'event_not_seated');
    });
});

describe('GET /v1/events/{event}/seats', function (): void {
    it('returns full statuses including hold_id for a staff bearer', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event, 'seatIds' => $seatIds] = seatedEventFixture($tenant->id, 2);

        app(TenantTransaction::class)->asTenant($tenant->id, function () use ($seatIds): void {
            EventSeat::query()->whereKey($seatIds[0])->update(['status' => EventSeatStatus::Blocked->value]);
        });

        $response = $this->getJson('/v1/events/'.$event->id.'/seats', [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsManageSeating]),
            'X-Tenant-Id' => $tenant->id,
        ]);

        $response->assertStatus(200)->assertConformsToOpenApi();

        $response->assertJsonCount(2, 'data');

        $byId = collect($response->json('data'))->keyBy('event_seat_id');
        expect($byId[$seatIds[0]]['status'])->toBe('blocked');
        expect($byId[$seatIds[1]]['status'])->toBe('available');
    });

    it('filters by status and ticket_type_id', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event, 'seatIds' => $seatIds] = seatedEventFixture($tenant->id, 2);

        app(TenantTransaction::class)->asTenant($tenant->id, function () use ($seatIds): void {
            EventSeat::query()->whereKey($seatIds[0])->update(['status' => EventSeatStatus::Blocked->value]);
        });

        $this->getJson('/v1/events/'.$event->id.'/seats?filter[status]=blocked', [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsManageSeating]),
            'X-Tenant-Id' => $tenant->id,
        ])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_seat_id', $seatIds[0]);
    });

    it('rejects an unknown filter', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event] = seatedEventFixture($tenant->id, 1);

        $this->getJson('/v1/events/'.$event->id.'/seats?filter[unknown]=x', [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsManageSeating]),
            'X-Tenant-Id' => $tenant->id,
        ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('denies a staff bearer without events.manage_seating', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event] = seatedEventFixture($tenant->id, 1);

        $this->getJson('/v1/events/'.$event->id.'/seats', [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsView]),
            'X-Tenant-Id' => $tenant->id,
        ])
            ->assertStatus(403)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('returns event_not_found for a cross-tenant event', function (): void {
        $tenantA = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        $tenantB = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event] = seatedEventFixture($tenantA->id, 1);

        $this->getJson('/v1/events/'.$event->id.'/seats', [
            'Authorization' => 'Bearer '.TenantStaff::token($tenantB->id, [Capability::EventsManageSeating]),
            'X-Tenant-Id' => $tenantB->id,
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'event_not_found');
    });
});

describe('PATCH /v1/events/{event}/seats', function (): void {
    it('applies block, unblock, and assign_ticket_type operations and adjusts counters', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event, 'ticketType' => $ticketType, 'seatIds' => $seatIds] = seatedEventFixture($tenant->id, 4);

        $secondTicketType = app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant, $event) {
            $type = TicketType::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'requires_seat' => true]);
            TicketTypeInventory::factory()->create(['tenant_id' => $tenant->id, 'ticket_type_id' => $type->id, 'quantity' => 0, 'held' => 0, 'sold' => 0]);

            return $type;
        });

        app(TenantTransaction::class)->asTenant($tenant->id, function () use ($seatIds): void {
            EventSeat::query()->whereKey($seatIds[1])->update(['status' => EventSeatStatus::Blocked->value]);
        });

        $response = $this->patchJson('/v1/events/'.$event->id.'/seats', [
            'operations' => [
                ['event_seat_id' => $seatIds[0], 'op' => 'block'],
                ['event_seat_id' => $seatIds[1], 'op' => 'unblock'],
                ['event_seat_id' => $seatIds[2], 'op' => 'assign_ticket_type', 'ticket_type_id' => $secondTicketType->id],
            ],
        ], [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsManageSeating]),
            'X-Tenant-Id' => $tenant->id,
        ]);

        $response->assertStatus(200)->assertConformsToOpenApi();
        $response->assertJsonCount(3, 'seats');

        app(TenantTransaction::class)->asTenant($tenant->id, function () use ($seatIds, $ticketType, $secondTicketType): void {
            expect(EventSeat::query()->whereKey($seatIds[0])->value('status'))->toBe(EventSeatStatus::Blocked);
            expect(EventSeat::query()->whereKey($seatIds[1])->value('status'))->toBe(EventSeatStatus::Available);
            expect(EventSeat::query()->whereKey($seatIds[2])->value('ticket_type_id'))->toBe($secondTicketType->id);

            // Counter equals seat-count invariant after every mutation
            // (stage-06 plan, Risks: "Seated counter double-accounting").
            $firstQuantity = TicketTypeInventory::query()->where('ticket_type_id', $ticketType->id)->value('quantity');
            $secondQuantity = TicketTypeInventory::query()->where('ticket_type_id', $secondTicketType->id)->value('quantity');

            expect($firstQuantity)->toBe(3); // started at 4: -1 block, +1 unblock, -1 rezoned away
            expect($secondQuantity)->toBe(1);
        });
    });

    it('records an activity-log entry for a successful seat mutation, and none for a read', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event, 'seatIds' => $seatIds] = seatedEventFixture($tenant->id, 2);

        $headers = [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsManageSeating]),
            'X-Tenant-Id' => $tenant->id,
        ];

        $this->getJson('/v1/events/'.$event->id.'/seats', $headers)->assertStatus(200);

        $this->patchJson('/v1/events/'.$event->id.'/seats', [
            'operations' => [['event_seat_id' => $seatIds[0], 'op' => 'block']],
        ], $headers)->assertStatus(200);

        $entries = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => DB::table('activity_log')->where('tenant_id', $tenant->id)->get()->all(),
        );

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->event)->toBe('mutation')
            ->and($entries[0]->description)->toContain('PATCH')
            ->and($entries[0]->causer_id)->not->toBeNull();
    });

    it('rolls back the whole batch and lists offending event_seat_ids on 409 seat_not_modifiable', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event, 'ticketType' => $ticketType, 'seatIds' => $seatIds] = seatedEventFixture($tenant->id, 2);

        app(TenantTransaction::class)->asTenant($tenant->id, function () use ($seatIds): void {
            EventSeat::query()->whereKey($seatIds[1])->update(['status' => EventSeatStatus::Held->value]);
        });

        $response = $this->patchJson('/v1/events/'.$event->id.'/seats', [
            'operations' => [
                ['event_seat_id' => $seatIds[0], 'op' => 'block'],
                ['event_seat_id' => $seatIds[1], 'op' => 'block'],
            ],
        ], [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsManageSeating]),
            'X-Tenant-Id' => $tenant->id,
        ]);

        $response->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'seat_not_modifiable')
            ->assertJsonPath('errors.event_seat_ids.0', $seatIds[1]);

        app(TenantTransaction::class)->asTenant($tenant->id, function () use ($seatIds, $ticketType): void {
            // All-or-nothing: the first operation's own valid guard passed,
            // but the whole batch, including its counter adjustment, rolled
            // back with the second operation's failure.
            expect(EventSeat::query()->whereKey($seatIds[0])->value('status'))->toBe(EventSeatStatus::Available);
            expect(TicketTypeInventory::query()->where('ticket_type_id', $ticketType->id)->value('quantity'))->toBe(2);
        });
    });

    it('rejects an unknown op with 422 request.validation_failed', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event, 'seatIds' => $seatIds] = seatedEventFixture($tenant->id, 1);

        $this->patchJson('/v1/events/'.$event->id.'/seats', [
            'operations' => [
                ['event_seat_id' => $seatIds[0], 'op' => 'melt'],
            ],
        ], [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsManageSeating]),
            'X-Tenant-Id' => $tenant->id,
        ])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('denies a staff bearer without events.manage_seating', function (): void {
        $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event, 'seatIds' => $seatIds] = seatedEventFixture($tenant->id, 1);

        $this->patchJson('/v1/events/'.$event->id.'/seats', [
            'operations' => [['event_seat_id' => $seatIds[0], 'op' => 'block']],
        ], [
            'Authorization' => 'Bearer '.TenantStaff::token($tenant->id, [Capability::EventsView]),
            'X-Tenant-Id' => $tenant->id,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'missing_capability');
    });

    it('returns event_not_found for a cross-tenant event', function (): void {
        $tenantA = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        $tenantB = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());
        ['event' => $event, 'seatIds' => $seatIds] = seatedEventFixture($tenantA->id, 1);

        $this->patchJson('/v1/events/'.$event->id.'/seats', [
            'operations' => [['event_seat_id' => $seatIds[0], 'op' => 'block']],
        ], [
            'Authorization' => 'Bearer '.TenantStaff::token($tenantB->id, [Capability::EventsManageSeating]),
            'X-Tenant-Id' => $tenantB->id,
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'event_not_found');
    });
});
