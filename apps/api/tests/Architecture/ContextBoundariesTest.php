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
