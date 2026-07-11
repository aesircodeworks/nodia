<?php

namespace App\Inventory\Actions;

use App\EventCatalog\Actions\ResolveEventForHold;
use App\EventCatalog\Data\HoldableEventData;
use App\EventCatalog\Data\HoldableTicketTypeData;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Data\HoldData;
use App\Inventory\Data\HoldItemInputData;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Events\HoldCreated;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Exceptions\InsufficientHoldInventoryException;
use App\Inventory\Exceptions\SalesWindowClosedException;
use App\Inventory\Exceptions\SeatSelectionInvalidException;
use App\Inventory\Exceptions\SeatUnavailableException;
use App\Inventory\Exceptions\TicketTypeNotInEventException;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\Hold;
use App\Inventory\Models\HoldItem;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/storefront/holds (stage-06 plan, TDD sequencing Slice 2 and
 * Slice 6; Endpoints "POST /v1/storefront/holds"). Runs entirely inside
 * the ambient request transaction App\Tenancy\Http\Middleware\
 * ResolveTenantFromHost already opened, so the hold row, its items, the
 * per-item held-increment guard, the per-item seat claim, and the
 * HoldCreated outbox row all commit or roll back together.
 *
 * seat_ids is a flat, top-level list on CreateHoldData, partitioned
 * positionally across the requires_seat items in request order (each
 * such item's own quantity claims the next slice): the structural check
 * (assertSeatSelectionValid) validates only that this partition adds up
 * (every requires_seat item gets exactly quantity seat ids, no GA item
 * gets any, no duplicates) using nothing but the request and Catalog's
 * requires_seat flags, before any seat row is read. Whether a given slice
 * actually resolves to available seats of the right event and zone is a
 * conflict, not a validation failure (Endpoints table: "wrong zone" is
 * seat_unavailable, not seat_selection_invalid), decided only by
 * claimSeats's own conditional UPDATE.
 *
 * Every requires_seat item still runs through claim() too: a seated
 * ticket type keeps its ticket_type_inventory counter row in sync from
 * zoning onward (stage-06 plan, event_seats section: "A seated hold
 * therefore performs both the counter UPDATE and the seat UPDATEs ...
 * and both must succeed or the transaction rolls back").
 */
final class CreateHold
{
    private const TTL_MINUTES = 10;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OutboxRecorder $outbox,
        private readonly ResolveEventForHold $resolveEvent,
    ) {}

    public function __invoke(CreateHoldData $data, ?string $customerId): HoldData
    {
        $tenantId = $this->tenantContext->tenantId();

        $event = ($this->resolveEvent)($data->eventId) ?? throw HoldEventNotFoundException::forId($data->eventId);

        $now = Date::now();

        foreach ($data->items as $item) {
            $this->assertHoldable($event, $item, $now);
        }

        $seatIds = $data->seatIds instanceof Optional ? [] : $data->seatIds;
        $seatSlices = $this->partitionSeatIds($event, $data->items, $seatIds);

        $hold = Hold::create([
            'tenant_id' => $tenantId,
            'event_id' => $data->eventId,
            'customer_id' => $customerId,
            'status' => HoldStatus::Active,
            'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES),
        ]);

        foreach ($data->items as $item) {
            $this->claim($item->ticketTypeId, $item->quantity);

            HoldItem::create([
                'tenant_id' => $tenantId,
                'hold_id' => $hold->id,
                'ticket_type_id' => $item->ticketTypeId,
                'quantity' => $item->quantity,
            ]);

            if (isset($seatSlices[$item->ticketTypeId])) {
                $this->claimSeats($event->id, $hold->id, $item->ticketTypeId, $seatSlices[$item->ticketTypeId]);
            }
        }

        $hold->setRelation('items', $hold->items()->get());

        $this->outbox->record(HoldCreated::fromHold($hold, $seatIds));

        return HoldData::fromModel($hold, $seatIds);
    }

    /**
     * Structural check only (stage-06 plan, Endpoints: "Seated type
     * without seats, seat count not matching quantity, or seats on a GA
     * type" -> 422 seat_selection_invalid): no seat row is read here.
     * Returns the seat_ids partitioned per requires_seat ticket_type_id,
     * in request order.
     *
     * @param  list<HoldItemInputData>  $items
     * @param  list<string>  $seatIds
     * @return array<string, list<string>>
     */
    private function partitionSeatIds(HoldableEventData $event, array $items, array $seatIds): array
    {
        if (count(array_unique($seatIds)) !== count($seatIds)) {
            throw SeatSelectionInvalidException::create();
        }

        $cursor = 0;
        $slices = [];

        foreach ($items as $item) {
            $ticketType = $this->findTicketType($event, $item->ticketTypeId);

            if ($ticketType === null || ! $ticketType->requiresSeat) {
                continue;
            }

            $slice = array_slice($seatIds, $cursor, $item->quantity);

            if (count($slice) !== $item->quantity) {
                throw SeatSelectionInvalidException::create();
            }

            $cursor += $item->quantity;
            $slices[$item->ticketTypeId] = $slice;
        }

        if ($cursor !== count($seatIds)) {
            throw SeatSelectionInvalidException::create();
        }

        return $slices;
    }

    /**
     * The seat-claim conditional UPDATE (stage-06 plan, event_seats
     * section: "available -> held (hold creation): ... WHERE id IN (...)
     * AND event_id = :event AND status = 'available' AND ticket_type_id =
     * :type, one statement per ticket type in the selection"). Affected-
     * row count is compared against the requested slice, never a read-
     * then-write existence check; the exact offending ids for the error
     * extension member are read back afterward by elimination, still
     * inside this same transaction, so nothing here decides eligibility
     * on stale data.
     *
     * @param  list<string>  $seatIds
     */
    private function claimSeats(string $eventId, string $holdId, string $ticketTypeId, array $seatIds): void
    {
        $affected = EventSeat::query()
            ->whereIn('id', $seatIds)
            ->where('event_id', $eventId)
            ->where('ticket_type_id', $ticketTypeId)
            ->where('status', EventSeatStatus::Available->value)
            ->update([
                'status' => EventSeatStatus::Held->value,
                'hold_id' => $holdId,
                'updated_at' => Date::now(),
            ]);

        if ($affected === count($seatIds)) {
            return;
        }

        $claimed = EventSeat::query()
            ->whereIn('id', $seatIds)
            ->where('hold_id', $holdId)
            ->where('status', EventSeatStatus::Held->value)
            ->pluck('id')
            ->all();

        throw SeatUnavailableException::forSeats(array_values(array_diff($seatIds, $claimed)));
    }

    private function assertHoldable(HoldableEventData $event, HoldItemInputData $item, CarbonInterface $now): void
    {
        $ticketType = $this->findTicketType($event, $item->ticketTypeId)
            ?? throw TicketTypeNotInEventException::forId($item->ticketTypeId);

        if ($ticketType->salesStart !== null && $now->lt($ticketType->salesStart)) {
            throw SalesWindowClosedException::forTicketType($item->ticketTypeId);
        }

        if ($ticketType->salesEnd !== null && $now->gt($ticketType->salesEnd)) {
            throw SalesWindowClosedException::forTicketType($item->ticketTypeId);
        }
    }

    private function findTicketType(HoldableEventData $event, string $ticketTypeId): ?HoldableTicketTypeData
    {
        foreach ($event->ticketTypes as $ticketType) {
            if ($ticketType->id === $ticketTypeId) {
                return $ticketType;
            }
        }

        return null;
    }

    /**
     * The held-increment guard (stage-06 plan, Data model
     * "ticket_type_inventory"): `UPDATE ticket_type_inventory SET held =
     * held + :n WHERE ticket_type_id = :id AND sold + held + :n <=
     * quantity`, a conditional UPDATE checked by affected-row count,
     * never a read-then-write existence check (master plan test-first
     * rule 2).
     */
    private function claim(string $ticketTypeId, int $quantity): void
    {
        $affected = TicketTypeInventory::query()
            ->where('ticket_type_id', $ticketTypeId)
            ->whereRaw('sold + held + ? <= quantity', [$quantity])
            ->update([
                'held' => DB::raw(sprintf('held + %d', $quantity)),
                'updated_at' => Date::now(),
            ]);

        if ($affected === 0) {
            throw InsufficientHoldInventoryException::forTicketType($ticketTypeId);
        }
    }
}
