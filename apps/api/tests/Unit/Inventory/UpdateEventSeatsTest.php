<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\TicketType;
use App\EventCatalog\Models\Venue;
use App\Inventory\Actions\AdjustInventoryQuantity;
use App\Inventory\Actions\UpdateEventSeats;
use App\Inventory\Data\UpdateEventSeatOperationData;
use App\Inventory\Data\UpdateEventSeatsData;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Exceptions\SeatNotModifiableException;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, Slice 7, task breakdown item 11: unit coverage for the
 * block/unblock/assign_ticket_type guards and their paired counter
 * adjustments, including the "blocking a held seat affects zero rows"
 * case and the counter-equals-seat-count invariant after every
 * mutation.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
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

/**
 * @return array{event: Event, ticketTypeA: TicketType, ticketTypeB: TicketType, seatIds: list<string>}
 */
function updateSeatsFixture(string $tenantId, int $seatCount): array
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
        $ticketTypeA = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id, 'requires_seat' => true]);
        $ticketTypeB = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $event->id, 'requires_seat' => true]);

        TicketTypeInventory::factory()->create(['tenant_id' => $tenantId, 'ticket_type_id' => $ticketTypeA->id, 'quantity' => $seatCount, 'held' => 0, 'sold' => 0]);
        TicketTypeInventory::factory()->create(['tenant_id' => $tenantId, 'ticket_type_id' => $ticketTypeB->id, 'quantity' => 0, 'held' => 0, 'sold' => 0]);

        $seatIds = [];

        for ($i = 0; $i < $seatCount; $i++) {
            $templateSeat = Seat::factory()->create(['tenant_id' => $tenantId, 'seat_map_id' => $seatMap->id, 'section' => 'A', 'row' => '1', 'number' => (string) ($i + 1)]);

            $eventSeat = EventSeat::factory()->create([
                'tenant_id' => $tenantId,
                'event_id' => $event->id,
                'seat_id' => $templateSeat->id,
                'ticket_type_id' => $ticketTypeA->id,
                'status' => EventSeatStatus::Available,
            ]);

            $seatIds[] = $eventSeat->id;
        }

        return ['event' => $event, 'ticketTypeA' => $ticketTypeA, 'ticketTypeB' => $ticketTypeB, 'seatIds' => $seatIds];
    });
}

function seatQuantity(string $ticketTypeId): int
{
    return TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->value('quantity');
}

it('blocks an available seat and decrements its type counter', function (): void {
    ['event' => $event, 'ticketTypeA' => $ticketTypeA, 'seatIds' => $seatIds] = updateSeatsFixture($this->tenantId, 2);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event, $ticketTypeA, $seatIds): void {
        $updated = app(UpdateEventSeats::class)($event->id, new UpdateEventSeatsData([
            new UpdateEventSeatOperationData($seatIds[0], 'block'),
        ]));

        expect($updated)->toHaveCount(1);
        expect(EventSeat::query()->whereKey($seatIds[0])->value('status'))->toBe(EventSeatStatus::Blocked);
        expect(seatQuantity($ticketTypeA->id))->toBe(1);
    });
});

it('blocking a held seat affects zero rows and leaves the counter untouched', function (): void {
    ['event' => $event, 'ticketTypeA' => $ticketTypeA, 'seatIds' => $seatIds] = updateSeatsFixture($this->tenantId, 1);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event, $ticketTypeA, $seatIds): void {
        EventSeat::query()->whereKey($seatIds[0])->update(['status' => EventSeatStatus::Held->value]);

        expect(fn () => app(UpdateEventSeats::class)($event->id, new UpdateEventSeatsData([
            new UpdateEventSeatOperationData($seatIds[0], 'block'),
        ])))->toThrow(SeatNotModifiableException::class);

        expect(EventSeat::query()->whereKey($seatIds[0])->value('status'))->toBe(EventSeatStatus::Held);
        expect(seatQuantity($ticketTypeA->id))->toBe(1);
    });
});

it('unblocks a blocked seat and increments its type counter', function (): void {
    ['event' => $event, 'ticketTypeA' => $ticketTypeA, 'seatIds' => $seatIds] = updateSeatsFixture($this->tenantId, 1);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event, $ticketTypeA, $seatIds): void {
        EventSeat::query()->whereKey($seatIds[0])->update(['status' => EventSeatStatus::Blocked->value]);
        app(AdjustInventoryQuantity::class)($ticketTypeA->id, -1);

        app(UpdateEventSeats::class)($event->id, new UpdateEventSeatsData([
            new UpdateEventSeatOperationData($seatIds[0], 'unblock'),
        ]));

        expect(EventSeat::query()->whereKey($seatIds[0])->value('status'))->toBe(EventSeatStatus::Available);
        expect(seatQuantity($ticketTypeA->id))->toBe(1);
    });
});

it('rezones an available seat, decrementing the old type and incrementing the new one', function (): void {
    ['event' => $event, 'ticketTypeA' => $ticketTypeA, 'ticketTypeB' => $ticketTypeB, 'seatIds' => $seatIds] = updateSeatsFixture($this->tenantId, 1);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event, $ticketTypeA, $ticketTypeB, $seatIds): void {
        app(UpdateEventSeats::class)($event->id, new UpdateEventSeatsData([
            new UpdateEventSeatOperationData($seatIds[0], 'assign_ticket_type', $ticketTypeB->id),
        ]));

        expect(EventSeat::query()->whereKey($seatIds[0])->value('ticket_type_id'))->toBe($ticketTypeB->id);
        expect(seatQuantity($ticketTypeA->id))->toBe(0);
        expect(seatQuantity($ticketTypeB->id))->toBe(1);
    });
});

it('unzones an available seat by assigning a null ticket_type_id', function (): void {
    ['event' => $event, 'ticketTypeA' => $ticketTypeA, 'seatIds' => $seatIds] = updateSeatsFixture($this->tenantId, 1);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event, $ticketTypeA, $seatIds): void {
        app(UpdateEventSeats::class)($event->id, new UpdateEventSeatsData([
            new UpdateEventSeatOperationData($seatIds[0], 'assign_ticket_type', null),
        ]));

        expect(EventSeat::query()->whereKey($seatIds[0])->value('ticket_type_id'))->toBeNull();
        expect(seatQuantity($ticketTypeA->id))->toBe(0);
    });
});

it('rejects assigning a ticket type that does not require a seat, rolling back the whole batch', function (): void {
    ['event' => $event, 'ticketTypeA' => $ticketTypeA, 'seatIds' => $seatIds] = updateSeatsFixture($this->tenantId, 2);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event, $ticketTypeA, $seatIds): void {
        $gaTicketType = TicketType::factory()->create(['tenant_id' => $this->tenantId, 'event_id' => $event->id, 'requires_seat' => false]);

        try {
            app(UpdateEventSeats::class)($event->id, new UpdateEventSeatsData([
                new UpdateEventSeatOperationData($seatIds[0], 'block'),
                new UpdateEventSeatOperationData($seatIds[1], 'assign_ticket_type', $gaTicketType->id),
            ]));

            $this->fail('Expected SeatNotModifiableException.');
        } catch (SeatNotModifiableException $exception) {
            expect($exception->errors()['event_seat_ids'])->toBe([$seatIds[1]]);
        }

        expect(EventSeat::query()->whereKey($seatIds[0])->value('status'))->toBe(EventSeatStatus::Available);
        expect(seatQuantity($ticketTypeA->id))->toBe(2);
    });
});

it('rejects assigning a ticket type from another event', function (): void {
    ['event' => $event, 'seatIds' => $seatIds] = updateSeatsFixture($this->tenantId, 1);
    ['ticketTypeA' => $foreignTicketType] = updateSeatsFixture($this->tenantId, 1);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($event, $seatIds, $foreignTicketType): void {
        expect(fn () => app(UpdateEventSeats::class)($event->id, new UpdateEventSeatsData([
            new UpdateEventSeatOperationData($seatIds[0], 'assign_ticket_type', $foreignTicketType->id),
        ])))->toThrow(SeatNotModifiableException::class);
    });
});
