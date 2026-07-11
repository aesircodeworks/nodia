<?php

namespace App\Console\Commands;

use App\EventCatalog\Support\Search\SearchIndexRebuilder;
use Illuminate\Console\Command;

/**
 * Rebuilds the event_search_documents projection by scanning current
 * published events directly, tenant by tenant, rather than rescanning
 * the outbox (system-design 9.1's replay primitive, stage-05c plan
 * Domain events: search documents derive entirely from current event
 * state, so this is a sanctioned deviation for this one disposable,
 * derived index).
 */
class SearchRebuildCommand extends Command
{
    protected $signature = 'search:rebuild';

    protected $description = 'Rebuild the search index from current published events, tenant by tenant';

    public function handle(SearchIndexRebuilder $rebuilder): int
    {
        $tenants = $rebuilder->rebuild();

        $this->info("Rebuilt the search index for {$tenants} tenant(s).");

        return self::SUCCESS;
    }
}
