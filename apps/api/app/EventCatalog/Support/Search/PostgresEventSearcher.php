<?php

namespace App\EventCatalog\Support\Search;

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The postgres driver behind App\EventCatalog\Support\Search\EventSearcher
 * (stage-05c plan, task breakdown item 8, Endpoints "Storefront search").
 * Bound in App\EventCatalog\EventCatalogServiceProvider when
 * config('search.driver') is postgres, the only driver this stage
 * implements (system-design 15.3: Meilisearch is the designated upgrade,
 * deferred behind this same interface).
 *
 * Joins event_search_documents to events and reapplies the published
 * scope at query time (events.status = published) rather than trusting
 * the projection alone, so a stale document row a race or a bug left
 * behind can never leak a draft or canceled event through search (stage-
 * 05c plan, Endpoints: "reapply the Stage 5a published-visibility scope
 * at query time"). The query is parsed with websearch_to_tsquery using
 * the same locale-to-regconfig mapping
 * (EventSearchDocumentBuilder::regconfigFor) the projector used to build
 * the matched document's own search_vector, ranked with ts_rank_cd, and
 * ordered by rank then a deterministic tie-break: event_starts_at
 * ascending (the column event_search_documents itself denormalizes,
 * matching the plan's literal wording), then events.id ascending, so
 * repeated identical requests always return the same order (relevance
 * order is not a stable cursor key, api-conventions, hence page
 * pagination rather than cursor pagination here).
 */
final class PostgresEventSearcher implements EventSearcher
{
    /**
     * @return LengthAwarePaginator<int, Event>
     */
    public function search(string $query, string $locale): LengthAwarePaginator
    {
        $regconfig = EventSearchDocumentBuilder::regconfigFor($locale);

        return Event::query()
            ->join('event_search_documents as search_document', 'search_document.event_id', '=', 'events.id')
            ->where('search_document.locale', $locale)
            ->where('events.status', EventStatus::Published)
            ->whereRaw('search_document.search_vector @@ websearch_to_tsquery(?::regconfig, ?)', [$regconfig, $query])
            ->select('events.*')
            ->selectRaw('ts_rank_cd(search_document.search_vector, websearch_to_tsquery(?::regconfig, ?)) as search_rank', [$regconfig, $query])
            ->orderByDesc('search_rank')
            ->orderBy('search_document.event_starts_at')
            ->orderBy('events.id')
            ->paginate();
    }
}
