<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\TicketType;
use App\EventCatalog\Models\Venue;
use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Exceptions\SeatSelectionInvalidException;
use App\Inventory\Exceptions\SeatUnavailableException;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\Hold;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, Slice 6, task breakdown item 10: unit coverage for the
 * per-type seat UPDATE statements' affected-row-count checks, and mixed
 * GA-plus-seated holds touching both mechanisms atomically.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('hold_items')->where('tenant_id', $this->tenantId)->delete();
        DB::table('event_seats')->where('tenant_id', $this->tenantId)->delete();
        DB::table('holds')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        TicketType::query()->where('tenant_id', $this->tenantId)->delete();
        Event::query()->where('tenant_id', $this->tenantId)->delete();
        Seat::query()->where('tenant_id', $this->tenantId)->delete();
        SeatMap::query()->where('tenant_id', $this->tenantId)->delete();
        Venue::query()->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * A seated, zoned event: two materialized, available seats already
 * assigned to a requires_seat ticket type (mirroring the post-zoning
 * state App\Inventory\Actions\MaterializeEventSeats plus the admin PATCH
 * zoning operation would leave behind), plus a GA ticket type for the
 * mixed-hold cases.
 *
 * @return array{event: Event, seatedTicketTypeId: string, gaTicketTypeId: string, seatAId: string, seatBId: string}
 */
function seatedHoldFixture(string $tenantId): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): array {
        $venue = Venue::factory()->create(['tenant_id' => $tenantId]);
        $seatMap = SeatMap::factory()->create(['tenant_id' => $tenantId, 'venue_id' => $venue->id]);
        $templateSeatA = Seat::factory()->create(['tenant_id' => $tenantId, 'seat_map_id' => $seatMap->id, 'section' => 'A', 'row' => '1', 'number' => '1']);
        $templateSeatB = Seat::factory()->create(['tenant_id' => $tenantId, 'seat_map_id' => $seatMap->id, 'section' => 'A', 'row' => '1', 'number' => '2']);
        $event = Event::factory()->atVenue($venue->id)->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published, 'seat_map_id' => $seatMap->id]);

        $seatedTicketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id, 'requires_seat' => true]);
        TicketTypeInventory::factory()->create(['tenant_id' => $tenantId, 'ticket_type_id' => $seatedTicketType->id, 'quantity' => 2, 'held' => 0, 'sold' => 0]);

        $gaTicketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id, 'requires_seat' => false]);
        TicketTypeInventory::factory()->create(['tenant_id' => $tenantId, 'ticket_type_id' => $gaTicketType->id, 'quantity' => 10, 'held' => 0, 'sold' => 0]);

        $seatA = EventSeat::factory()->create([
            'tenant_id' => $tenantId,
            'event_id' => $event->id,
            'seat_id' => $templateSeatA->id,
            'ticket_type_id' => $seatedTicketType->id,
            'status' => EventSeatStatus::Available,
        ]);
        $seatB = EventSeat::factory()->create([
            'tenant_id' => $tenantId,
            'event_id' => $event->id,
            'seat_id' => $templateSeatB->id,
            'ticket_type_id' => $seatedTicketType->id,
            'status' => EventSeatStatus::Available,
        ]);

        return [
            'event' => $event,
            'seatedTicketTypeId' => $seatedTicketType->id,
            'gaTicketTypeId' => $gaTicketType->id,
            'seatAId' => $seatA->id,
            'seatBId' => $seatB->id,
        ];
    });
}

it('claims the counter and flips the selected seats to held', function () {
    $fixture = seatedHoldFixture($this->tenantId);

    $data = CreateHoldData::from([
        'event_id' => $fixture['event']->id,
        'items' => [['ticket_type_id' => $fixture['seatedTicketTypeId'], 'quantity' => 2]],
        'seat_ids' => [$fixture['seatAId'], $fixture['seatBId']],
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($result->seatIds)->toEqualCanonicalizing([$fixture['seatAId'], $fixture['seatBId']]);

    $counter = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $fixture['seatedTicketTypeId'])->first(),
    );

    expect($counter->held)->toBe(2);

    $seats = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSeat::query()->whereIn('id', [$fixture['seatAId'], $fixture['seatBId']])->get(),
    );

    foreach ($seats as $seat) {
        expect($seat->status)->toBe(EventSeatStatus::Held)
            ->and($seat->hold_id)->toBe($result->id);
    }
});

it('claims both mechanisms atomically for a mixed GA-plus-seated hold', function () {
    $fixture = seatedHoldFixture($this->tenantId);

    $data = CreateHoldData::from([
        'event_id' => $fixture['event']->id,
        'items' => [
            ['ticket_type_id' => $fixture['gaTicketTypeId'], 'quantity' => 3],
            ['ticket_type_id' => $fixture['seatedTicketTypeId'], 'quantity' => 1],
        ],
        'seat_ids' => [$fixture['seatAId']],
    ]);

    $result = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($result->seatIds)->toBe([$fixture['seatAId']]);

    $gaCounter = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $fixture['gaTicketTypeId'])->first(),
    );
    $seatedCounter = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $fixture['seatedTicketTypeId'])->first(),
    );

    expect($gaCounter->held)->toBe(3)
        ->and($seatedCounter->held)->toBe(1);

    $seatA = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSeat::query()->whereKey($fixture['seatAId'])->first(),
    );

    expect($seatA->status)->toBe(EventSeatStatus::Held);
});

it('throws SeatSelectionInvalidException when a requires_seat item has no seat_ids', function () {
    $fixture = seatedHoldFixture($this->tenantId);

    $data = CreateHoldData::from([
        'event_id' => $fixture['event']->id,
        'items' => [['ticket_type_id' => $fixture['seatedTicketTypeId'], 'quantity' => 2]],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(SeatSelectionInvalidException::class);
});

it('throws SeatSelectionInvalidException when the seat count does not match quantity', function () {
    $fixture = seatedHoldFixture($this->tenantId);

    $data = CreateHoldData::from([
        'event_id' => $fixture['event']->id,
        'items' => [['ticket_type_id' => $fixture['seatedTicketTypeId'], 'quantity' => 2]],
        'seat_ids' => [$fixture['seatAId']],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(SeatSelectionInvalidException::class);
});

it('throws SeatSelectionInvalidException when seat_ids are given for a GA-only request', function () {
    $fixture = seatedHoldFixture($this->tenantId);

    $data = CreateHoldData::from([
        'event_id' => $fixture['event']->id,
        'items' => [['ticket_type_id' => $fixture['gaTicketTypeId'], 'quantity' => 1]],
        'seat_ids' => [$fixture['seatAId']],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(SeatSelectionInvalidException::class);
});

it('throws SeatSelectionInvalidException for duplicate seat_ids', function () {
    $fixture = seatedHoldFixture($this->tenantId);

    $data = CreateHoldData::from([
        'event_id' => $fixture['event']->id,
        'items' => [['ticket_type_id' => $fixture['seatedTicketTypeId'], 'quantity' => 2]],
        'seat_ids' => [$fixture['seatAId'], $fixture['seatAId']],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(SeatSelectionInvalidException::class);
});

it('throws SeatUnavailableException naming the offending seat when a seat is already held, rolling back the counter', function () {
    $fixture = seatedHoldFixture($this->tenantId);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($fixture): void {
        $blocker = Hold::factory()->create(['tenant_id' => $this->tenantId, 'event_id' => $fixture['event']->id]);
        EventSeat::query()->whereKey($fixture['seatAId'])->update(['status' => EventSeatStatus::Held->value, 'hold_id' => $blocker->id]);
    });

    $data = CreateHoldData::from([
        'event_id' => $fixture['event']->id,
        'items' => [['ticket_type_id' => $fixture['seatedTicketTypeId'], 'quantity' => 2]],
        'seat_ids' => [$fixture['seatAId'], $fixture['seatBId']],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(function (SeatUnavailableException $e) use ($fixture): void {
        expect($e->errors())->toBe(['seat_ids' => [$fixture['seatAId']]]);
    });

    $counter = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $fixture['seatedTicketTypeId'])->first(),
    );
    $seatB = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSeat::query()->whereKey($fixture['seatBId'])->first(),
    );

    expect($counter->held)->toBe(0)
        ->and($seatB->status)->toBe(EventSeatStatus::Available)
        ->and($seatB->hold_id)->toBeNull();
});

it('throws SeatUnavailableException for a seat belonging to a different event', function () {
    $fixture = seatedHoldFixture($this->tenantId);
    $otherFixture = seatedHoldFixture($this->tenantId);

    $data = CreateHoldData::from([
        'event_id' => $fixture['event']->id,
        'items' => [['ticket_type_id' => $fixture['seatedTicketTypeId'], 'quantity' => 1]],
        'seat_ids' => [$otherFixture['seatAId']],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(SeatUnavailableException::class);
});

it('throws SeatUnavailableException for a seat with an unknown id', function () {
    $fixture = seatedHoldFixture($this->tenantId);

    $data = CreateHoldData::from([
        'event_id' => $fixture['event']->id,
        'items' => [['ticket_type_id' => $fixture['seatedTicketTypeId'], 'quantity' => 1]],
        'seat_ids' => [(string) Str::uuid7()],
    ]);

    $invoke = fn () => app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => app(CreateHold::class)($data, null),
    );

    expect($invoke)->toThrow(SeatUnavailableException::class);
});
