<?php

use App\Inventory\Http\Controllers\AvailabilityController;
use App\Inventory\Http\Controllers\HoldController;
use App\Inventory\Http\Controllers\QueueEntryController;
use App\Inventory\Http\Controllers\StorefrontEventSeatController;
use Illuminate\Support\Facades\Route;

// Registered under the tenancy.storefront group (Host resolution;
// EnforceCustomerTenantClaim already validates any bearer's tenant_id
// claim ahead of these handlers) via App\Inventory\InventoryServiceProvider
// (stage-06 plan, task breakdown item 4). Guest checkout: no
// authentication is required to create or read a hold. `hold_creation`
// (stage-10 plan, Endpoints "Rate limiting tiers") is the strictest
// named tier, checked per IP and, when a customer bearer token is
// present, per customer; only the creating endpoint carries it, since
// GET and DELETE by id already require possessing the hold's own
// anti-enumeration UUID as the capability.
Route::post('/storefront/holds', [HoldController::class, 'store'])->middleware('throttle:hold_creation');
Route::get('/storefront/holds/{hold}', [HoldController::class, 'show'])->whereUuid('hold');
Route::delete('/storefront/holds/{hold}', [HoldController::class, 'destroy'])->whereUuid('hold');

// Task breakdown item 7 (TDD Slice 3): the database-backed availability
// read, database-authoritative and unauthenticated like every other
// storefront route in this file. `browse` (stage-10 plan, Endpoints
// "Rate limiting tiers") is the generous named tier shared by every
// read-only storefront route.
Route::get('/storefront/events/{event}/availability', [AvailabilityController::class, 'index'])->whereUuid('event')->middleware('throttle:browse');

// Task breakdown item 11 (TDD Slice 7): the storefront seat read, database-
// backed and unauthenticated like every other storefront route in this
// file.
Route::get('/storefront/events/{event}/seats', [StorefrontEventSeatController::class, 'index'])->whereUuid('event')->middleware('throttle:browse');

// Task breakdown item 7 (TDD Slice 4): the waiting-room entrant lifecycle,
// unauthenticated like every other storefront route in this file; the
// entrant id returned by the join is the capability the poll route
// accepts (stage-10 plan, Endpoints "Storefront", section 14.4).
// `queue_entry` and `queue_poll` (stage-10 plan, Endpoints "Rate
// limiting tiers") were registered ahead of these routes by task
// breakdown item 4.
Route::post('/storefront/events/{event}/queue-entries', [QueueEntryController::class, 'store'])->whereUuid('event')->middleware('throttle:queue_entry');
Route::get('/storefront/queue-entries/{entry}', [QueueEntryController::class, 'show'])->whereUuid('entry')->middleware('throttle:queue_poll');
