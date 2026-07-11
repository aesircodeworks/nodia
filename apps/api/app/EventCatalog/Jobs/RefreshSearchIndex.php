<?php

namespace App\EventCatalog\Jobs;

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Support\Search\EventSearchDocumentBuilder;
use App\EventCatalog\Support\Search\EventSearchDocumentRow;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function __construct(private EventSearchDocumentBuilder $builder) {}

    public function handle(OutboxEvent $event): void
    {
        $model = Event::query()->find($event->aggregate_id);

        if ($model === null || $model->status !== EventStatus::Published) {
            $this->deleteDocuments($event->aggregate_id);

            return;
        }

        foreach ($this->builder->documentsFor($model) as $row) {
            $this->upsert($row);
        }
    }

    private function deleteDocuments(string $eventId): void
    {
        DB::table('event_search_documents')->where('event_id', $eventId)->delete();
    }

    /**
     * Raw INSERT ... ON CONFLICT (event_id, locale) DO UPDATE, the upsert
     * the plan's Data model section names as what makes concurrent
     * duplicate deliveries harmless: a second insert of the same
     * (event_id, locale) pair overwrites the same content rather than
     * creating a second row. id is generated fresh on every call but only
     * ever takes effect on the INSERT path; an existing row keeps its own
     * id (not part of the DO UPDATE SET). search_vector reuses
     * EventSearchDocumentBuilder's own SQL fragment and bindings rather
     * than duplicating the setweight() expression here.
     */
    private function upsert(EventSearchDocumentRow $row): void
    {
        $searchVectorSql = EventSearchDocumentBuilder::searchVectorSql();

        $sql = <<<SQL
            insert into event_search_documents
                (id, tenant_id, event_id, locale, name, description, event_starts_at, search_vector, created_at, updated_at)
            values (?, ?, ?, ?, ?, ?, ?, {$searchVectorSql}, now(), now())
            on conflict (event_id, locale) do update set
                name = excluded.name,
                description = excluded.description,
                event_starts_at = excluded.event_starts_at,
                search_vector = excluded.search_vector,
                updated_at = excluded.updated_at
            SQL;

        DB::statement($sql, [
            (string) Str::uuid7(),
            $row->tenantId,
            $row->eventId,
            $row->locale,
            $row->name,
            $row->description,
            $row->eventStartsAt,
            ...EventSearchDocumentBuilder::searchVectorBindings($row),
        ]);
    }
}
