<?php

/*
 * Stage-05c plan, task breakdown item 8, TDD sequencing Slice 6:
 * "controllers depend on the EventSearcher interface, never the Postgres
 * class". App\EventCatalog\EventCatalogServiceProvider is the one place
 * allowed to know the concrete implementation, since it is what resolves
 * config('search.driver') into a container binding
 * (App\EventCatalog\Support\Search\EventSearcher); every other caller,
 * present or future (the storefront search controller lands in the next
 * task), must depend on the interface so a Meilisearch driver can be
 * swapped in later purely as a binding change (system-design 15.3).
 */
arch('nothing but the EventCatalog service provider depends on the Postgres event searcher directly')
    ->expect('App\EventCatalog\Support\Search\PostgresEventSearcher')
    ->toOnlyBeUsedIn('App\EventCatalog\EventCatalogServiceProvider');
