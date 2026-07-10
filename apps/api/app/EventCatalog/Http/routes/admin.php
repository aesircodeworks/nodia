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
// Task breakdown item 5 (TDD slice 2) adds the events routes below,
// gated by events.view for reads and events.manage for writes;
// events.publish (publish/cancel) and ticket-types routes are task
// breakdown items 8 and 9. Publish/cancel and ticket-types routes land in
// later tasks.

use App\EventCatalog\Http\Controllers\EventController;
use App\EventCatalog\Http\Controllers\VenueController;
use App\Http\Middleware\RecordActivityAudit;
use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireCapability::class.':'.Capability::EventsView->value)->group(function (): void {
    Route::get('/venues', [VenueController::class, 'index']);
    Route::get('/venues/{venue}', [VenueController::class, 'show'])->whereUuid('venue');
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show'])->whereUuid('event');
});

Route::middleware([RequireCapability::class.':'.Capability::EventsManage->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/venues', [VenueController::class, 'store']);
    Route::patch('/venues/{venue}', [VenueController::class, 'update'])->whereUuid('venue');
    Route::post('/events', [EventController::class, 'store']);
    Route::patch('/events/{event}', [EventController::class, 'update'])->whereUuid('event');
});
