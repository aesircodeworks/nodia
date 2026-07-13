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
use App\Inventory\Exceptions\AdmissionInvalidException;
use App\Inventory\Exceptions\AdmissionRequiredException;
use App\Inventory\Exceptions\CustomerRequiredException;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Exceptions\InsufficientHoldInventoryException;
use App\Inventory\Exceptions\PurchaseLimitExceededException;
use App\Inventory\Exceptions\SalesWindowClosedException;
use App\Inventory\Exceptions\SeatSelectionInvalidException;
use App\Inventory\Exceptions\SeatUnavailableException;
use App\Inventory\Exceptions\TicketTypeNotInEventException;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\Hold;
use App\Inventory\Models\HoldItem;
use App\Inventory\Models\TicketTypeInventory;
use App\Inventory\Support\AdmissionToken;
use App\Inventory\Support\PurchaseCounters;
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
 *
 * Purchase limits (stage-10 plan, Data model "purchase_counters";
 * Endpoints "POST /v1/storefront/holds"): an item whose ticket type
 * carries max_per_customer requires an authenticated customer
 * (customer_required, checked in assertHoldable alongside the existing
 * sales-window and membership checks, before any inventory statement
 * runs) and increments App\Inventory\Support\PurchaseCounters inside this
 * same transaction, right after the item's held-increment guard. Zero
 * affected rows there means the limit is exceeded (purchase_limit_exceeded)
 * and rolls the whole hold back. The quantity actually counted is
 * persisted on the hold item as counted_quantity, zero for an unlimited
 * ticket type, so release and expiry can reverse exactly what this
 * transaction recorded regardless of any later policy change.
 *
 * Admission enforcement (stage-10 plan, Endpoints "POST
 * /v1/storefront/holds"; task breakdown item 9): for an event whose
 * on_sale_policy.high_demand is true, assertAdmitted runs first, right
 * after the event resolves and strictly before assertHoldable's own
 * per-item loop, so no inventory or counter statement runs on any
 * denial path. A missing header throws admission_required; a present
 * but invalid one (wrong event, wrong tenant, bad signature, unknown
 * key ID, or expired against the injected clock, all checked
 * statelessly by App\Inventory\Support\AdmissionToken::verify against
 * the current and previous signing keys) throws admission_invalid. The
 * check is stateless and does not consume the token: it stays valid for
 * its full TTL so a buyer whose hold attempt fails can retry with the
 * hold-endpoint retry posture (stage-10 plan, Admission token: "It
 * remains valid for its full TTL... abuse within the TTL is bounded by
 * the hold-creation rate tier and the purchase limits").
 */
final class CreateHold
{
    private const TTL_MINUTES = 10;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OutboxRecorder $outbox,
        private readonly ResolveEventForHold $resolveEvent,
    ) {}

    public function __invoke(CreateHoldData $data, ?string $customerId, ?string $admissionToken = null): HoldData
    {
        $tenantId = $this->tenantContext->tenantId();

        $event = ($this->resolveEvent)($data->eventId) ?? throw HoldEventNotFoundException::forId($data->eventId);

        $now = Date::now();

        $this->assertAdmitted($event, $tenantId, $admissionToken, $now);

        foreach ($data->items as $item) {
            $this->assertHoldable($event, $item, $now, $customerId);
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

            $countedQuantity = $this->claimPurchaseLimit($tenantId, $event, $item, $customerId);

            HoldItem::create([
                'tenant_id' => $tenantId,
                'hold_id' => $hold->id,
                'ticket_type_id' => $item->ticketTypeId,
                'quantity' => $item->quantity,
                'counted_quantity' => $countedQuantity,
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

    /**
     * The admission gate (stage-10 plan, Endpoints "POST
     * /v1/storefront/holds"): a no-op unless the event is flagged
     * on_sale_policy.high_demand. AdmissionToken::verify does not
     * distinguish which check failed (unknown key ID, bad signature,
     * wrong event, wrong tenant, or expired), so every one of those
     * renders the same admission_invalid problem; a missing header
     * renders admission_required instead, distinguished here rather
     * than folded into verify() since "no token presented" and "token
     * presented but invalid" are different rows in the plan's own
     * failure table.
     */
    private function assertAdmitted(HoldableEventData $event, string $tenantId, ?string $admissionToken, CarbonInterface $now): void
    {
        if (! $event->onSalePolicy->highDemand) {
            return;
        }

        if ($admissionToken === null || $admissionToken === '') {
            throw AdmissionRequiredException::forEvent($event->id);
        }

        if (AdmissionToken::verify($admissionToken, $event->id, $tenantId, $now) === null) {
            throw AdmissionInvalidException::forEvent($event->id);
        }
    }

    private function assertHoldable(HoldableEventData $event, HoldItemInputData $item, CarbonInterface $now, ?string $customerId): void
    {
        $ticketType = $this->findTicketType($event, $item->ticketTypeId)
            ?? throw TicketTypeNotInEventException::forId($item->ticketTypeId);

        if ($ticketType->maxPerCustomer !== null && $customerId === null) {
            throw CustomerRequiredException::forTicketType($item->ticketTypeId);
        }

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

    /**
     * The purchase-counter guard (stage-10 plan, Data model
     * "purchase_counters"): a no-op for a ticket type with no
     * max_per_customer, matching App\Inventory\Support\PurchaseCounters::
     * increment's own null-limit skip. assertHoldable already requires a
     * customer id for every limited item before this runs
     * (CustomerRequiredException), so $customerId is guaranteed non-null
     * here whenever $ticketType->maxPerCustomer is set; the null check
     * below is defense in depth, never the primary guard.
     *
     * The item's own quantity is checked against the limit before the
     * upsert runs (stage-10 plan, Data model "purchase_counters": "The
     * insert path is guarded by request validation (:n <= :limit,
     * deterministic, no race)"): Postgres only applies an `ON CONFLICT DO
     * UPDATE ... WHERE` guard to the update branch, so a customer's first
     * ever counter row for this ticket type would otherwise insert
     * unconditionally regardless of how far past the limit the request's
     * own quantity is. This check is exactly the n <= limit case (the
     * counter starts at zero), so combined with the upsert's own WHERE on
     * every later conflict, both branches enforce the same invariant.
     *
     * @return int the item's counted_quantity: the item's own quantity
     *             when the ticket type is limited, zero otherwise
     */
    private function claimPurchaseLimit(string $tenantId, HoldableEventData $event, HoldItemInputData $item, ?string $customerId): int
    {
        $ticketType = $this->findTicketType($event, $item->ticketTypeId);

        if ($ticketType === null || $ticketType->maxPerCustomer === null) {
            return 0;
        }

        if ($customerId === null) {
            throw CustomerRequiredException::forTicketType($item->ticketTypeId);
        }

        if ($item->quantity > $ticketType->maxPerCustomer) {
            throw PurchaseLimitExceededException::forTicketType($item->ticketTypeId, $ticketType->maxPerCustomer);
        }

        $ok = PurchaseCounters::increment($tenantId, $customerId, $item->ticketTypeId, $item->quantity, $ticketType->maxPerCustomer);

        if (! $ok) {
            throw PurchaseLimitExceededException::forTicketType($item->ticketTypeId, $ticketType->maxPerCustomer);
        }

        return $item->quantity;
    }
}
