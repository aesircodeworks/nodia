<?php

use App\EventCatalog\Http\Controllers\StorefrontEventController;
use Illuminate\Support\Facades\Route;

// Registered under the tenancy.storefront group (Host resolution, no staff
// identity) via EventCatalogServiceProvider, mounted under /v1 (stage-05a
// plan, task breakdown item 10). The /v1/storefront prefix is the routing
// seam between the Host-resolved and X-Tenant-Id-resolved populations
// (system-design 4.1). Read-only, published surface only.
Route::get('/storefront/events', [StorefrontEventController::class, 'index']);
Route::get('/storefront/events/{event}', [StorefrontEventController::class, 'show'])->whereUuid('event');
