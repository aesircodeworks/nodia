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

foreach ($contexts as $context) {
    arch("only the {$context} context uses its own Models")
        ->expect("App\\{$context}\\Models")
        ->toOnlyBeUsedIn("App\\{$context}");
}
