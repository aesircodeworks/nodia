<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\EventData;
use App\EventCatalog\Data\UpdateEventData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Events\EventUpdated;
use App\EventCatalog\Exceptions\EventImmutableException;
use App\EventCatalog\Exceptions\EventNotFoundException;
use App\EventCatalog\Exceptions\SeatMapVenueMismatchException;
use App\EventCatalog\Exceptions\SeatMapVirtualEventException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\SeatMap;
use App\Support\Outbox\OutboxRecorder;
use Spatie\LaravelData\Optional;

/**
 * PATCH /v1/events/{event} (stage-05a plan, task breakdown item 5). Every
 * field is optional; a field absent from the payload is left untouched,
 * mirroring App\EventCatalog\Actions\UpdateVenue. UpdateEventData's own
 * withValidator hook already guarantees the venue/virtual trio and the
 * start/end pair each arrive either fully absent or fully present and
 * mutually consistent (App\EventCatalog\Data\Concerns\
 * ValidatesEventInvariants), so this Action never needs to merge against
 * the model's current values to decide whether the write is valid.
 * EventUpdated is recorded on every successful call, unconditionally
 * (stage-05a plan, Domain events), unlike
 * App\Identity\Actions\AssignRole's same-value no-op skip: this stage's
 * plan names no such exception for events.
 *
 * The canceled-event immutability guard (endpoint table: catalog.event_
 * immutable) is a locking recheck inside the request transaction, never
 * a controller read-then-write: the row is taken FOR UPDATE and its
 * status re-read before the write, so a cancel committing concurrently is
 * either seen here (this call blocks on the row lock, then observes
 * canceled and rejects) or blocked until this transaction commits, closing
 * the race where a canceled event could still be modified (CLAUDE.md:
 * invariant-guarding writes are conditional, never read-then-write).
 *
 * seat_map_id linkage (stage-05b plan, Endpoints: "PATCH /v1/events/
 * {event} (extension of the Stage 5a endpoint)") is validated here, not in
 * UpdateEventData: the check needs a database read (does the seat map
 * exist and belong to the effective venue?) and the target Event's
 * current row (a field UpdateEventData does not carry is left at its
 * current value, mirroring every other field's own "leave untouched"
 * semantics), mirroring catalog.currency_mismatch's own precedent of a
 * boundary check living in the Action (CreateTicketType). The check runs
 * against the *effective* venue_id/is_virtual/seat_map_id - each field
 * given in the payload, or the locked row's current value otherwise - so
 * changing venue_id or flipping is_virtual while seat_map_id stays set
 * (given or already stored) is rejected exactly like setting a mismatched
 * seat_map_id directly, unless the same request clears the link to null.
 */
final class UpdateEvent
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function __invoke(Event $event, UpdateEventData $data): EventData
    {
        $event = Event::query()->whereKey($event->getKey())->lockForUpdate()->first()
            ?? throw EventNotFoundException::forId((string) $event->getKey());

        if ($event->status === EventStatus::Canceled) {
            throw EventImmutableException::forId($event->id);
        }

        $attributes = [];

        if (! $data->name instanceof Optional) {
            $attributes['name'] = $data->name;
        }

        if (! $data->description instanceof Optional) {
            $attributes['description'] = $data->description;
        }

        if (! $data->venueId instanceof Optional) {
            $attributes['venue_id'] = $data->venueId;
        }

        if (! $data->isVirtual instanceof Optional) {
            $attributes['is_virtual'] = $data->isVirtual;
        }

        if (! $data->virtualEventUrl instanceof Optional) {
            $attributes['virtual_event_url'] = $data->virtualEventUrl;
        }

        if (! $data->startAt instanceof Optional) {
            $attributes['start_at'] = $data->startAt;
        }

        if (! $data->endAt instanceof Optional) {
            $attributes['end_at'] = $data->endAt;
        }

        if (! $data->timezone instanceof Optional) {
            $attributes['timezone'] = $data->timezone;
        }

        if (! $data->asyncPaymentPolicy instanceof Optional) {
            $attributes['async_payment_policy'] = $data->asyncPaymentPolicy;
        }

        if (! $data->onSalePolicy instanceof Optional) {
            $attributes['on_sale_policy'] = $data->onSalePolicy;
        }

        if (! $data->seatMapId instanceof Optional) {
            $attributes['seat_map_id'] = $data->seatMapId;
        }

        $this->assertSeatMapLinkage($event, $attributes);

        $event->update($attributes);

        $this->outbox->record(EventUpdated::fromEvent($event));

        return EventData::fromModel($event->refresh());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertSeatMapLinkage(Event $event, array $attributes): void
    {
        $seatMapId = array_key_exists('seat_map_id', $attributes) ? $attributes['seat_map_id'] : $event->seat_map_id;

        if ($seatMapId === null) {
            return;
        }

        $isVirtual = array_key_exists('is_virtual', $attributes) ? $attributes['is_virtual'] : $event->is_virtual;

        if ($isVirtual) {
            throw SeatMapVirtualEventException::forEvent($event->id);
        }

        $venueId = array_key_exists('venue_id', $attributes) ? $attributes['venue_id'] : $event->venue_id;

        $seatMap = SeatMap::query()->find($seatMapId);

        if ($seatMap === null || $seatMap->venue_id !== $venueId) {
            throw SeatMapVenueMismatchException::forSeatMap($seatMapId, $venueId);
        }
    }
}
