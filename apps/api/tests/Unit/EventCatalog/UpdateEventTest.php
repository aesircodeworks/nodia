<?php

use App\EventCatalog\Actions\UpdateEvent;
use App\EventCatalog\Data\EventData;
use App\EventCatalog\Data\UpdateEventData;
use App\EventCatalog\Exceptions\SeatMapVenueMismatchException;
use App\EventCatalog\Exceptions\SeatMapVirtualEventException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\Venue;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => ['en' => 'Before'],
            'timezone' => 'UTC',
        ]),
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        // outbox_deliveries before outbox_events: App\EventCatalog\Jobs\
        // RefreshSearchIndex now subscribes to EventUpdated (stage-05c
        // plan, task breakdown item 7), so a delivery row exists here and
        // its outbox_event_id foreign key blocks the parent delete
        // otherwise, mirroring tests/Feature/EventCatalog/
        // EventUpdatedOutboxTest.php's own cleanup order.
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        OutboxEvent::query()->where('tenant_id', $this->tenantId)->delete();
        // events before seat_maps: events.seat_map_id is on delete
        // restrict (stage-05b plan, Data model).
        Event::query()->where('tenant_id', $this->tenantId)->delete();
        SeatMap::query()->where('tenant_id', $this->tenantId)->delete();
        Venue::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * @return array{Venue, SeatMap}
 */
function seatMapOnVenue(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $venue = Venue::factory()->create(['tenant_id' => $tenantId]);
        $seatMap = SeatMap::factory()->create(['tenant_id' => $tenantId, 'venue_id' => $venue->id]);

        return [$venue, $seatMap];
    });
}

it('updates the given fields and returns EventData', function () {
    $data = UpdateEventData::from(['timezone' => 'America/Chicago']);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    expect($result)->toBeInstanceOf(EventData::class)
        ->and($result->timezone)->toBe('America/Chicago')
        ->and($result->name)->toBe(['en' => 'Before']);
});

it('leaves fields absent from the payload untouched', function () {
    $data = UpdateEventData::from(['timezone' => 'America/Chicago']);

    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    $fresh = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::query()->find($this->event->id),
    );

    expect($fresh->getTranslations('name'))->toBe(['en' => 'Before'])
        ->and($fresh->timezone)->toBe('America/Chicago');
});

it('updates the venue/virtual trio together', function () {
    $venue = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $data = UpdateEventData::from([
        'is_virtual' => false,
        'venue_id' => $venue->id,
        'virtual_event_url' => null,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    expect($result->isVirtual)->toBeFalse()
        ->and($result->venueId)->toBe($venue->id)
        ->and($result->virtualEventUrl)->toBeNull();
});

it('records an EventUpdated outbox row on every successful call', function () {
    $data = UpdateEventData::from(['timezone' => 'America/Chicago']);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => OutboxEvent::query()->where('type', 'EventUpdated')->where('aggregate_id', $result->id)->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->payload)->toBe(['event_id' => $result->id]);
});

it('sets seat_map_id when the seat map belongs to the event\'s venue', function () {
    [$venue, $seatMap] = seatMapOnVenue($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($venue): void {
        $this->event->update(['is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null]);
    });

    $data = UpdateEventData::from(['seat_map_id' => $seatMap->id]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    expect($result->seatMapId)->toBe($seatMap->id);
});

it('rejects a seat_map_id belonging to another venue with SeatMapVenueMismatchException', function () {
    [$venue] = seatMapOnVenue($this->tenantId);
    [$otherVenue, $seatMap] = seatMapOnVenue($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($venue): void {
        $this->event->update(['is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null]);
    });

    $data = UpdateEventData::from(['seat_map_id' => $seatMap->id]);

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    ))->toThrow(SeatMapVenueMismatchException::class);
});

it('rejects an unknown seat_map_id with SeatMapVenueMismatchException', function () {
    [$venue] = seatMapOnVenue($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($venue): void {
        $this->event->update(['is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null]);
    });

    $data = UpdateEventData::from(['seat_map_id' => (string) Str::uuid7()]);

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    ))->toThrow(SeatMapVenueMismatchException::class);
});

it('rejects a non-null seat_map_id on a virtual event with SeatMapVirtualEventException', function () {
    [, $seatMap] = seatMapOnVenue($this->tenantId);

    $data = UpdateEventData::from(['seat_map_id' => $seatMap->id]);

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    ))->toThrow(SeatMapVirtualEventException::class);
});

it('clears seat_map_id to null', function () {
    [$venue, $seatMap] = seatMapOnVenue($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($venue, $seatMap): void {
        $this->event->update([
            'is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null, 'seat_map_id' => $seatMap->id,
        ]);
    });

    $data = UpdateEventData::from(['seat_map_id' => null]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    expect($result->seatMapId)->toBeNull();
});

it('rejects changing venue_id while the stored seat_map_id stays set, unless the request clears it', function () {
    [$venue, $seatMap] = seatMapOnVenue($this->tenantId);
    [$otherVenue] = seatMapOnVenue($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($venue, $seatMap): void {
        $this->event->update([
            'is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null, 'seat_map_id' => $seatMap->id,
        ]);
    });

    $data = UpdateEventData::from(['is_virtual' => false, 'venue_id' => $otherVenue->id, 'virtual_event_url' => null]);

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    ))->toThrow(SeatMapVenueMismatchException::class);
});

it('allows changing venue_id when the same request clears the stored seat_map_id', function () {
    [$venue, $seatMap] = seatMapOnVenue($this->tenantId);
    [$otherVenue] = seatMapOnVenue($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($venue, $seatMap): void {
        $this->event->update([
            'is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null, 'seat_map_id' => $seatMap->id,
        ]);
    });

    $data = UpdateEventData::from([
        'is_virtual' => false, 'venue_id' => $otherVenue->id, 'virtual_event_url' => null, 'seat_map_id' => null,
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    expect($result->venueId)->toBe($otherVenue->id)
        ->and($result->seatMapId)->toBeNull();
});

it('rejects flipping is_virtual to true while the stored seat_map_id stays set, unless the request clears it', function () {
    [$venue, $seatMap] = seatMapOnVenue($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($venue, $seatMap): void {
        $this->event->update([
            'is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null, 'seat_map_id' => $seatMap->id,
        ]);
    });

    $data = UpdateEventData::from([
        'is_virtual' => true, 'venue_id' => null, 'virtual_event_url' => 'https://example.test/stream',
    ]);

    expect(fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    ))->toThrow(SeatMapVirtualEventException::class);
});

it('leaves seat_map_id untouched when the payload omits it', function () {
    [$venue, $seatMap] = seatMapOnVenue($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($venue, $seatMap): void {
        $this->event->update([
            'is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null, 'seat_map_id' => $seatMap->id,
        ]);
    });

    $data = UpdateEventData::from(['timezone' => 'America/Chicago']);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(UpdateEvent::class)($this->event, $data),
    );

    expect($result->seatMapId)->toBe($seatMap->id);
});
