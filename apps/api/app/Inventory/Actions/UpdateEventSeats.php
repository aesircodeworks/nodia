<?php

namespace App\Inventory\Actions;

use App\EventCatalog\Actions\ResolveEventForHold;
use App\EventCatalog\Data\HoldableEventData;
use App\EventCatalog\Data\HoldableTicketTypeData;
use App\Inventory\Data\UpdateEventSeatOperationData;
use App\Inventory\Data\UpdateEventSeatsData;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Exceptions\SeatNotModifiableException;
use App\Inventory\Models\EventSeat;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /v1/events/{event}/seats (stage-06 plan, Slice 7, task breakdown
 * item 11). Every operation in the request runs inside one transaction,
 * each as its own conditional UPDATE guarded by event_id and the seat's
 * current status, checked by affected-row count, never a read-then-write
 * existence check (master plan test-first rule 2): a locking read
 * (mirroring App\Inventory\Actions\SetTicketTypeQuantity's own
 * lockForUpdate precedent) precedes each guarded UPDATE only to capture
 * the seat's prior ticket_type_id for the paired counter adjustment, the
 * lock closing the race the UPDATE's own WHERE clause alone would leave
 * open between two concurrent PATCH requests targeting the same seat.
 *
 * Every operation still runs even after one fails, so every offending
 * event_seat_id can be reported together (stage-06 plan, Endpoints: "any
 * guard failure rolls back the whole batch with 409 seat_not_modifiable
 * listing the offending event_seat_ids"); the whole transaction rolls
 * back if the offending list is non-empty, so a partial batch is never
 * committed. Counter quantity adjustments (block/unblock and rezoning)
 * ride in the same transaction, per the event_seats section: "blocking
 * adjusts the affected counter" and "each zoning assignment increments
 * the target type's quantity (and decrements the previous type's, when
 * rezoning)".
 */
final class UpdateEventSeats
{
    public function __construct(
        private readonly ResolveEventForHold $resolveEvent,
        private readonly AdjustInventoryQuantity $adjust,
    ) {}

    /**
     * @return list<EventSeat>
     */
    public function __invoke(string $eventId, UpdateEventSeatsData $data): array
    {
        $event = ($this->resolveEvent)($eventId) ?? throw HoldEventNotFoundException::forId($eventId);

        return DB::transaction(function () use ($event, $data): array {
            $offending = [];
            $updated = [];

            foreach ($data->operations as $operation) {
                $seat = $this->applyOperation($event, $operation);

                if ($seat === null) {
                    $offending[] = $operation->eventSeatId;
                } else {
                    $updated[] = $seat;
                }
            }

            if ($offending !== []) {
                throw SeatNotModifiableException::forSeats($offending);
            }

            return $updated;
        });
    }

    private function applyOperation(HoldableEventData $event, UpdateEventSeatOperationData $operation): ?EventSeat
    {
        return match ($operation->op) {
            'block' => $this->block($event, $operation->eventSeatId),
            'unblock' => $this->unblock($event, $operation->eventSeatId),
            'assign_ticket_type' => $this->assign($event, $operation->eventSeatId, $operation->ticketTypeId),
            default => null,
        };
    }

    private function block(HoldableEventData $event, string $eventSeatId): ?EventSeat
    {
        $seat = $this->lockSeat($event->id, $eventSeatId);

        if ($seat === null) {
            return null;
        }

        $affected = EventSeat::query()
            ->whereKey($eventSeatId)
            ->where('status', EventSeatStatus::Available->value)
            ->update(['status' => EventSeatStatus::Blocked->value, 'updated_at' => Date::now()]);

        if ($affected !== 1) {
            return null;
        }

        if ($seat->ticket_type_id !== null) {
            ($this->adjust)($seat->ticket_type_id, -1);
        }

        return $seat->fresh();
    }

    private function unblock(HoldableEventData $event, string $eventSeatId): ?EventSeat
    {
        $seat = $this->lockSeat($event->id, $eventSeatId);

        if ($seat === null) {
            return null;
        }

        $affected = EventSeat::query()
            ->whereKey($eventSeatId)
            ->where('status', EventSeatStatus::Blocked->value)
            ->update(['status' => EventSeatStatus::Available->value, 'updated_at' => Date::now()]);

        if ($affected !== 1) {
            return null;
        }

        if ($seat->ticket_type_id !== null) {
            ($this->adjust)($seat->ticket_type_id, 1);
        }

        return $seat->fresh();
    }

    private function assign(HoldableEventData $event, string $eventSeatId, ?string $newTicketTypeId): ?EventSeat
    {
        if ($newTicketTypeId !== null && $this->findTicketType($event, $newTicketTypeId) === null) {
            return null;
        }

        $seat = $this->lockSeat($event->id, $eventSeatId);

        if ($seat === null) {
            return null;
        }

        $affected = EventSeat::query()
            ->whereKey($eventSeatId)
            ->where('status', EventSeatStatus::Available->value)
            ->update(['ticket_type_id' => $newTicketTypeId, 'updated_at' => Date::now()]);

        if ($affected !== 1) {
            return null;
        }

        if ($seat->ticket_type_id !== $newTicketTypeId) {
            if ($seat->ticket_type_id !== null) {
                ($this->adjust)($seat->ticket_type_id, -1);
            }

            if ($newTicketTypeId !== null) {
                ($this->adjust)($newTicketTypeId, 1);
            }
        }

        return $seat->fresh();
    }

    private function lockSeat(string $eventId, string $eventSeatId): ?EventSeat
    {
        return EventSeat::query()
            ->whereKey($eventSeatId)
            ->where('event_id', $eventId)
            ->lockForUpdate()
            ->first();
    }

    private function findTicketType(HoldableEventData $event, string $ticketTypeId): ?HoldableTicketTypeData
    {
        foreach ($event->ticketTypes as $ticketType) {
            if ($ticketType->id === $ticketTypeId && $ticketType->requiresSeat) {
                return $ticketType;
            }
        }

        return null;
    }
}
