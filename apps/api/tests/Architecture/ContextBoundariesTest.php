<?php

$contexts = [
    'Tenancy',
    'Identity',
    'EventCatalog',
    'Inventory',
    'Orders',
    'Payments',
    'CheckIn',
    'Reporting',
];

// Database\Factories is exempt: factories exist to construct their context's
// models and live outside App by framework convention; the boundary this test
// guards is between contexts, not between a context and its own factories.
foreach ($contexts as $context) {
    arch("only the {$context} context uses its own Models")
        ->expect("App\\{$context}\\Models")
        ->toOnlyBeUsedIn("App\\{$context}")
        ->ignoring("Database\\Factories\\{$context}");
}

// The Http layer (controllers, middleware, route wiring) is each context's
// delivery surface; other contexts integrate through Actions and events,
// never by importing another context's Http classes.
foreach ($contexts as $context) {
    arch("only the {$context} context uses its own Http layer")
        ->expect("App\\{$context}\\Http")
        ->toOnlyBeUsedIn("App\\{$context}");
}

// Domain events are recorded only by the owning context (event-conventions);
// other contexts never import a foreign Events/ tree. Support\Outbox knows
// only the DomainEvent contract, never concrete event classes.
foreach ($contexts as $context) {
    arch("only the {$context} context uses its own Events")
        ->expect("App\\{$context}\\Events")
        ->toOnlyBeUsedIn("App\\{$context}");
}

// The transactional outbox is shared infrastructure: it must not reach into
// any bounded context's models (stage-04 plan, Slice 1 architecture).
foreach ($contexts as $context) {
    arch("Support Outbox does not import {$context} models")
        ->expect('App\Support\Outbox')
        ->not->toUse("App\\{$context}\\Models");
}
