<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\EventData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Events\EventPublished;
use App\EventCatalog\Exceptions\EventNotPublishableException;
use App\EventCatalog\Models\Event;
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
 */
final class PublishEvent
{
    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function __invoke(Event $event): EventData
    {
        $publishedAt = Date::now();

        $affected = Event::query()
            ->whereKey($event->id)
            ->where('status', EventStatus::Draft)
            ->update(['status' => EventStatus::Published]);

        if ($affected !== 1) {
            throw EventNotPublishableException::forId($event->id);
        }

        $event = $event->fresh();

        $this->outbox->record(EventPublished::fromEvent($event, $publishedAt));

        return EventData::fromModel($event);
    }
}
