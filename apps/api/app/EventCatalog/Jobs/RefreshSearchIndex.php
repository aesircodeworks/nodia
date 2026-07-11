<?php

namespace App\EventCatalog\Jobs;

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Support\Search\EventSearchDocumentBuilder;
use App\EventCatalog\Support\Search\SearchDocumentWriter;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;

/**
 * The EventCatalog search projector (system-design 3.2, 9.2; stage-05c
 * plan, Data model 'event_search_documents' and Domain events). Registered
 * in App\EventCatalog\EventCatalogServiceProvider for EventCreated,
 * EventUpdated, EventPublished, and EventCanceled, and delivered by the
 * shared App\Support\Outbox\Jobs\ProcessOutboxDelivery, which already
 * guards this handler's invocation with the conditional
 * outbox_deliveries mark-processed UPDATE (event-conventions
 * idempotence) before calling handle() and already runs it inside the
 * event's own tenant transaction (RLS applies).
 *
 * Every event type is handled identically: reload the event by aggregate
 * id and act on its *current* status rather than the triggering event's
 * own payload. This is what the plan's Domain events table describes per
 * type (EventPublished/EventUpdated-while-published upsert, EventUpdated-
 * while-not-published/EventCanceled delete, EventCreated no-ops because a
 * draft is never published) and what also makes ordering irrelevant: an
 * EventUpdated delivered after a later EventCanceled still finds the
 * event canceled and deletes, converging regardless of delivery order
 * (stage-05c plan, Domain events: "no per-aggregate ordered
 * consumption").
 */
final readonly class RefreshSearchIndex implements OutboxSubscriber
{
    public const string NAME = 'refresh_search_index';

    public function __construct(
        private EventSearchDocumentBuilder $builder,
        private SearchDocumentWriter $writer,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $model = Event::query()->find($event->aggregate_id);

        if ($model === null || $model->status !== EventStatus::Published) {
            $this->writer->deleteFor($event->aggregate_id);

            return;
        }

        $rows = $this->builder->documentsFor($model);

        foreach ($rows as $row) {
            $this->writer->upsert($row);
        }

        $this->writer->deleteForExceptLocales(
            $event->aggregate_id,
            array_map(static fn ($row): string => $row->locale, $rows),
        );
    }
}
