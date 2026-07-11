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
// Stage-05c plan, task breakdown item 2 (TDD slice 2) adds the event
// media routes below: upload and list nest under the owning event and
// gate on events.manage/events.view like every other event mutation and
// read here. Deletion is not here: DELETE /v1/media/{media} is a
// top-level route in routes/api.php, since that one route also serves
// the later tenant-logo collection this context never touches
// (App\Http\Controllers\MediaController's own docblock).

use App\EventCatalog\Http\Controllers\EventController;
use App\EventCatalog\Http\Controllers\EventMediaController;
use App\EventCatalog\Http\Controllers\SeatMapController;
use App\EventCatalog\Http\Controllers\TicketTypeController;
use App\EventCatalog\Http\Controllers\VenueController;
use App\Http\Middleware\RecordActivityAudit;
use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireCapability::class.':'.Capability::EventsView->value)->group(function (): void {
    Route::get('/venues', [VenueController::class, 'index']);
    Route::get('/venues/{venue}', [VenueController::class, 'show'])->whereUuid('venue');
    Route::get('/venues/{venue}/seat-maps', [SeatMapController::class, 'index'])->whereUuid('venue');
    Route::get('/seat-maps/{seat_map}', [SeatMapController::class, 'show'])->whereUuid('seat_map');
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show'])->whereUuid('event');
    Route::get('/events/{event}/ticket-types', [TicketTypeController::class, 'index'])->whereUuid('event');
    Route::get('/ticket-types/{ticket_type}', [TicketTypeController::class, 'show'])->whereUuid('ticket_type');
    Route::get('/events/{event}/media', [EventMediaController::class, 'index'])->whereUuid('event');
});

Route::middleware([RequireCapability::class.':'.Capability::EventsManage->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/venues', [VenueController::class, 'store']);
    Route::patch('/venues/{venue}', [VenueController::class, 'update'])->whereUuid('venue');
    Route::post('/events', [EventController::class, 'store']);
    Route::patch('/events/{event}', [EventController::class, 'update'])->whereUuid('event');
    Route::post('/events/{event}/ticket-types', [TicketTypeController::class, 'store'])->whereUuid('event');
    Route::patch('/ticket-types/{ticket_type}', [TicketTypeController::class, 'update'])->whereUuid('ticket_type');
    Route::post('/events/{event}/media', [EventMediaController::class, 'store'])->whereUuid('event');
});

// Task breakdown item 2 (TDD slice 2, stage-05b plan): the seat map
// mutating surface is gated by its own seat_maps.manage capability, a
// third, dedicated group so RequireCapability checks that one rather than
// events.manage (mirroring the events.publish group's own precedent
// above for why a distinct capability needs its own group). Task
// breakdown item 4 (TDD slice 4) adds the PUT full-replace route below to
// the same group. Task breakdown item 5 (TDD slice 5) adds the DELETE
// route below to the same group.
Route::middleware([RequireCapability::class.':'.Capability::SeatMapsManage->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/venues/{venue}/seat-maps', [SeatMapController::class, 'store'])->whereUuid('venue');
    Route::put('/seat-maps/{seat_map}', [SeatMapController::class, 'update'])->whereUuid('seat_map');
    Route::delete('/seat-maps/{seat_map}', [SeatMapController::class, 'destroy'])->whereUuid('seat_map');
});

Route::middleware([RequireCapability::class.':'.Capability::EventsPublish->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/events/{event}/publish', [EventController::class, 'publish'])->whereUuid('event');
    Route::post('/events/{event}/cancel', [EventController::class, 'cancel'])->whereUuid('event');
});
