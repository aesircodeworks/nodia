<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storefront Event Search Driver
    |--------------------------------------------------------------------------
    |
    | Selects the App\EventCatalog\Support\Search\EventSearcher binding
    | (stage-05c plan, task breakdown item 8): postgres is the only driver
    | implemented, backed by the event_search_documents projection and
    | PostgreSQL full-text search. A meilisearch driver is the designated
    | upgrade path (system-design 15.3) but is deferred until PostgreSQL
    | full-text search demonstrably falls short; the config key exists now
    | so that swap is a container-binding change, not a call-site rewrite.
    |
    */

    'driver' => env('SEARCH_DRIVER', 'postgres'),

];
