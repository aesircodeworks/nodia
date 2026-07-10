<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via EventCatalogServiceProvider,
// mounted under /v1 (stage-05a plan, task breakdown item 2). Task
// breakdown item 3 (TDD slice 1) adds the venues routes below, gated by
// events.view for reads and events.manage for writes, mirroring the
// capability wiring tests/Feature/EventCatalog/CatalogAuthorizationMatrixTest.php
// already proved through probe routes. RecordActivityAudit sits
// alongside RequireCapability on the mutating-only inner group, never on
// the read routes, matching roles.php/memberships.php's own precedent.
// Task breakdown items 5, 8, and 9 populate this file with the events and
// ticket-types routes as each later slice's controller and Data objects
// land.

use App\EventCatalog\Http\Controllers\VenueController;
use App\Http\Middleware\RecordActivityAudit;
use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireCapability::class.':'.Capability::EventsView->value)->group(function (): void {
    Route::get('/venues', [VenueController::class, 'index']);
    Route::get('/venues/{venue}', [VenueController::class, 'show'])->whereUuid('venue');
});

Route::middleware([RequireCapability::class.':'.Capability::EventsManage->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/venues', [VenueController::class, 'store']);
    Route::patch('/venues/{venue}', [VenueController::class, 'update'])->whereUuid('venue');
});
