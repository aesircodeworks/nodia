<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\EventData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Events\EventPublished;
use App\EventCatalog\Exceptions\EventNotPublishableException;
use App\EventCatalog\Exceptions\SeatMapRequiredException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Seat;
use App\Inventory\Actions\MaterializeEventSeats;
use App\Support\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\Date;

/**
 * POST /v1/events/{event}/publish (stage-05a plan, task breakdown item
 * 9). A single conditional UPDATE guarded on the current status, checked
 * by affected-row count, never a read-then-write existence check
 * (master plan test-first rule 2; CLAUDE.md): two parallel publish
 * attempts against one draft can only ever see the row transition from
 * draft to published once, proven by
 * tests/Concurrency/EventLifecycleContentionTest.php. EventPublished is
 * recorded only when the affected-row count is 1, in the same
 * transaction as the UPDATE (the whole admin request already runs
 * inside one, App\Tenancy\Http\Middleware\ResolveTenantFromHeader).
 *
 * Stage 6 (Slice 5, task breakdown item 9) closes the seated-event
 * publish path this Action's own docblock left open: before the
 * conditional UPDATE, a requires_seat ticket type without a
 * seat_map_id is refused with SeatMapRequiredException, a shape
 * validation on the event's own data rather than a concurrency guard,
 * so a plain read-then-throw is correct here. After a successful
 * publish of a seated event (seat_map_id non-null), App\Inventory\
 * Actions\MaterializeEventSeats runs synchronously inside this same
 * transaction, materializing the template's seats onto the event and
 * seeding every requires_seat ticket type's counter at 0; a GA event
 * (seat_map_id null) materializes nothing.
 */
final class PublishEvent
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly MaterializeEventSeats $materializeEventSeats,
    ) {}

    public function __invoke(Event $event): EventData
    {
        $publishedAt = Date::now();

        $requiresSeatTicketTypeIds = $event->ticketTypes()
            ->where('requires_seat', true)
            ->pluck('id')
            ->all();

        if ($requiresSeatTicketTypeIds !== [] && $event->seat_map_id === null) {
            throw SeatMapRequiredException::forId($event->id);
        }

        $affected = Event::query()
            ->whereKey($event->id)
            ->where('status', EventStatus::Draft)
            ->update(['status' => EventStatus::Published]);

        if ($affected !== 1) {
            throw EventNotPublishableException::forId($event->id);
        }

        $event = $event->fresh();

        if ($event->seat_map_id !== null) {
            $seatIds = Seat::query()->where('seat_map_id', $event->seat_map_id)->pluck('id')->all();

            ($this->materializeEventSeats)($event->tenant_id, $event->id, $seatIds, $requiresSeatTicketTypeIds);
        }

        $this->outbox->record(EventPublished::fromEvent($event, $publishedAt));

        return EventData::fromModel($event);
    }
}
