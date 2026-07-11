<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via App\Inventory\InventoryServiceProvider
// (stage-06 plan, task breakdown item 7, TDD Slice 3). Gated by the same
// events.view capability App\EventCatalog\Http\Controllers\
// TicketTypeController's own read routes use, since this is another read
// on the same top-level ticket-types resource, just from the Inventory
// context.

use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Inventory\Http\Controllers\TicketTypeInventoryController;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireCapability::class.':'.Capability::EventsView->value)->group(function (): void {
    Route::get('/ticket-types/{ticket_type}/inventory', [TicketTypeInventoryController::class, 'show'])->whereUuid('ticket_type');
});
