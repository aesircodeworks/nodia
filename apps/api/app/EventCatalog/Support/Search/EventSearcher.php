<?php

namespace App\EventCatalog\Support\Search;

use App\EventCatalog\Models\Event;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The storefront search seam (stage-05c plan, task breakdown item 8;
 * system-design 15.3: PostgreSQL full-text search now, Meilisearch the
 * designated upgrade). App\EventCatalog\Support\Search\PostgresEventSearcher
 * is the only implementation today; the storefront controller (a later
 * task in this stage) and every other caller depend on this interface
 * only, never on the Postgres class directly, so swapping in a
 * Meilisearch driver later is a container-binding change in
 * App\EventCatalog\EventCatalogServiceProvider, not a call-site rewrite
 * (tests/Architecture/SearchSeamTest.php enforces this).
 */
interface EventSearcher
{
    /**
     * Searches published events in the given locale's search documents.
     * Matches the current storefront request's tenant scope implicitly
     * through RLS (App\Support\Tenancy\TenantContext already sets
     * app.tenant_id for the request), the same way every other storefront
     * and admin query in this codebase relies on RLS rather than an
     * explicit tenant_id parameter.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    public function search(string $query, string $locale): LengthAwarePaginator;
}
