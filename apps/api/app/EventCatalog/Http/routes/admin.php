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
// gated by events.view for reads and events.manage for writes.
// Task breakdown item 8 (TDD slice 3) adds the ticket-type routes below:
// creation and listing nest under the owning event, detail and update are
// top-level, per the api-conventions nesting rule. Task breakdown item 9
// (TDD slice 4) adds the publish/cancel routes below, gated by their own
// events.publish capability rather than events.manage: a third,
// dedicated group so RequireCapability checks the right one.

use App\EventCatalog\Http\Controllers\EventController;
use App\EventCatalog\Http\Controllers\TicketTypeController;
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
    Route::get('/events/{event}/ticket-types', [TicketTypeController::class, 'index'])->whereUuid('event');
    Route::get('/ticket-types/{ticket_type}', [TicketTypeController::class, 'show'])->whereUuid('ticket_type');
});

Route::middleware([RequireCapability::class.':'.Capability::EventsManage->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/venues', [VenueController::class, 'store']);
    Route::patch('/venues/{venue}', [VenueController::class, 'update'])->whereUuid('venue');
    Route::post('/events', [EventController::class, 'store']);
    Route::patch('/events/{event}', [EventController::class, 'update'])->whereUuid('event');
    Route::post('/events/{event}/ticket-types', [TicketTypeController::class, 'store'])->whereUuid('event');
    Route::patch('/ticket-types/{ticket_type}', [TicketTypeController::class, 'update'])->whereUuid('ticket_type');
});

Route::middleware([RequireCapability::class.':'.Capability::EventsPublish->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/events/{event}/publish', [EventController::class, 'publish'])->whereUuid('event');
    Route::post('/events/{event}/cancel', [EventController::class, 'cancel'])->whereUuid('event');
});
