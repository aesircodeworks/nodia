<?php

namespace App\Inventory\Actions;

use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\TicketTypeInventory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Materializes a seated event's `event_seats` rows on publish (stage-06
 * plan, Slice 5, task breakdown item 9; system-design 6.2). Called
 * synchronously from App\EventCatalog\Actions\PublishEvent inside the
 * same transaction as the publish's own conditional UPDATE, so a failure
 * anywhere in materialization rolls the whole publish back. Catalog
 * resolves and passes `$seatIds` and `$requiresSeatTicketTypeIds` rather
 * than this Action reading App\EventCatalog's seats or ticket_types
 * tables itself: a context never touches another context's models or
 * tables directly (CLAUDE.md).
 *
 * Every seat materializes unzoned (`ticket_type_id` null, status
 * `available`); each `requires_seat` ticket type's counter row is seeded
 * at `quantity` 0, left for the admin zoning operation to adjust (stage-
 * 06 plan, event_seats section: "Nothing upstream carries zoning before
 * publish"). Idempotent per event: `insertOrIgnore` relies on the
 * `event_seats` unique(event_id, seat_id) constraint to silently skip
 * seats already materialized, and the counter seed is skipped outright
 * when a row already exists, so a second invocation for the same event
 * never duplicates either.
 */
final class MaterializeEventSeats
{
    public function __construct(private readonly InitializeTicketTypeInventory $initializeInventory) {}

    /**
     * @param  list<string>  $seatIds
     * @param  list<string>  $requiresSeatTicketTypeIds
     */
    public function __invoke(string $tenantId, string $eventId, array $seatIds, array $requiresSeatTicketTypeIds): void
    {
        DB::transaction(function () use ($tenantId, $eventId, $seatIds, $requiresSeatTicketTypeIds): void {
            $this->materializeSeats($tenantId, $eventId, $seatIds);
            $this->seedCounters($tenantId, $requiresSeatTicketTypeIds);
        });
    }

    /**
     * @param  list<string>  $seatIds
     */
    private function materializeSeats(string $tenantId, string $eventId, array $seatIds): void
    {
        if ($seatIds === []) {
            return;
        }

        $now = Date::now();

        $rows = array_map(fn (string $seatId): array => [
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'seat_id' => $seatId,
            'ticket_type_id' => null,
            'status' => EventSeatStatus::Available->value,
            'hold_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $seatIds);

        EventSeat::query()->insertOrIgnore($rows);
    }

    /**
     * @param  list<string>  $requiresSeatTicketTypeIds
     */
    private function seedCounters(string $tenantId, array $requiresSeatTicketTypeIds): void
    {
        foreach ($requiresSeatTicketTypeIds as $ticketTypeId) {
            $exists = TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->exists();

            if (! $exists) {
                ($this->initializeInventory)($tenantId, $ticketTypeId, 0);
            }
        }
    }
}
