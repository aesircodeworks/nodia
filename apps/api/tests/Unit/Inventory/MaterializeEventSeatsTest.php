<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\TicketType;
use App\EventCatalog\Models\Venue;
use App\Inventory\Actions\MaterializeEventSeats;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, Slice 5, task breakdown item 9: unit coverage for
 * MaterializeEventSeats, written first per the master plan double loop.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create()->id,
    );

    $this->fixture = app(TenantTransaction::class)->asTenant($this->tenantId, function () {
        $venue = Venue::factory()->create(['tenant_id' => $this->tenantId]);
        $seatMap = SeatMap::factory()->create(['tenant_id' => $this->tenantId, 'venue_id' => $venue->id]);
        $seatA = Seat::factory()->create(['tenant_id' => $this->tenantId, 'seat_map_id' => $seatMap->id, 'section' => 'A', 'row' => '1', 'number' => '1']);
        $seatB = Seat::factory()->create(['tenant_id' => $this->tenantId, 'seat_map_id' => $seatMap->id, 'section' => 'A', 'row' => '1', 'number' => '2']);
        $event = Event::factory()->atVenue($venue->id)->create(['tenant_id' => $this->tenantId, 'seat_map_id' => $seatMap->id]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $this->tenantId, 'event_id' => $event->id, 'requires_seat' => true]);

        return [
            'event' => $event,
            'seatIds' => [$seatA->id, $seatB->id],
            'ticketTypeId' => $ticketType->id,
        ];
    });
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('event_seats')->where('tenant_id', $this->tenantId)->delete();
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

it('creates one event_seats row per template seat, unzoned and available', function () {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        app(MaterializeEventSeats::class)(
            $this->tenantId,
            $this->fixture['event']->id,
            $this->fixture['seatIds'],
            [],
        );
    });

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSeat::query()->where('event_id', $this->fixture['event']->id)->get(),
    );

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row->ticket_type_id)->toBeNull()
            ->and($row->status)->toBe(EventSeatStatus::Available)
            ->and($row->hold_id)->toBeNull()
            ->and($this->fixture['seatIds'])->toContain($row->seat_id);
    }
});

it('initializes each requires_seat ticket type counter quantity to 0', function () {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        app(MaterializeEventSeats::class)(
            $this->tenantId,
            $this->fixture['event']->id,
            $this->fixture['seatIds'],
            [$this->fixture['ticketTypeId']],
        );
    });

    $counter = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->fixture['ticketTypeId'])->first(),
    );

    expect($counter)->not->toBeNull()
        ->and($counter->quantity)->toBe(0)
        ->and($counter->held)->toBe(0)
        ->and($counter->sold)->toBe(0);
});

it('is idempotent on re-materialization: no duplicate seats or counters', function () {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        app(MaterializeEventSeats::class)(
            $this->tenantId,
            $this->fixture['event']->id,
            $this->fixture['seatIds'],
            [$this->fixture['ticketTypeId']],
        );

        app(MaterializeEventSeats::class)(
            $this->tenantId,
            $this->fixture['event']->id,
            $this->fixture['seatIds'],
            [$this->fixture['ticketTypeId']],
        );
    });

    $seatCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSeat::query()->where('event_id', $this->fixture['event']->id)->count(),
    );

    $counterCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => TicketTypeInventory::query()->where('ticket_type_id', $this->fixture['ticketTypeId'])->count(),
    );

    expect($seatCount)->toBe(2)
        ->and($counterCount)->toBe(1);
});

it('rolls back atomically when a seat id does not resolve to a real seat', function () {
    $bogusSeatId = (string) Str::uuid7();

    expect(function () use ($bogusSeatId): void {
        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($bogusSeatId): void {
            app(MaterializeEventSeats::class)(
                $this->tenantId,
                $this->fixture['event']->id,
                [$this->fixture['seatIds'][0], $bogusSeatId],
                [],
            );
        });
    })->toThrow(QueryException::class);

    $rows = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSeat::query()->where('event_id', $this->fixture['event']->id)->count(),
    );

    expect($rows)->toBe(0);
});
