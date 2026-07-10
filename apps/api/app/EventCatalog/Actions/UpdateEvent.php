<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\EventData;
use App\EventCatalog\Data\UpdateEventData;
use App\EventCatalog\Events\EventUpdated;
use App\EventCatalog\Models\Event;
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
 */
final class UpdateEvent
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function __invoke(Event $event, UpdateEventData $data): EventData
    {
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

        $event->update($attributes);

        $this->outbox->record(EventUpdated::fromEvent($event));

        return EventData::fromModel($event->refresh());
    }
}
