<?php

namespace App\EventCatalog\Support\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shared write path for event_search_documents rows, extracted so
 * both App\EventCatalog\Jobs\RefreshSearchIndex (the outbox projector,
 * stage-05c task-07) and App\Console\Commands\SearchRebuildCommand (the
 * state-scan rebuild, task-10) embed the exact same upsert and delete
 * statements rather than maintaining two copies of the same SQL.
 */
final class SearchDocumentWriter
{
    public function deleteFor(string $eventId): void
    {
        DB::table('event_search_documents')->where('event_id', $eventId)->delete();
    }

    /**
     * Raw INSERT ... ON CONFLICT (event_id, locale) DO UPDATE: a second
     * write of the same (event_id, locale) pair overwrites the same
     * content rather than creating a second row, which is what makes
     * concurrent duplicate deliveries (and a rebuild running over an
     * already-populated table) harmless. id is generated fresh on every
     * call but only ever takes effect on the INSERT path; an existing row
     * keeps its own id (not part of the DO UPDATE SET).
     */
    public function upsert(EventSearchDocumentRow $row): void
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
