<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via IdentityServiceProvider, mounted
// under /v1 (stage-07 plan, task breakdown item 10). Read-only and
// capability-gated: the customer lookup exposes PII, so unlike roles and
// memberships reads it requires customers.view rather than bare
// membership.

use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Identity\Http\Controllers\CustomerLookupController;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireCapability::class.':'.Capability::CustomersView->value)->group(function (): void {
    Route::get('/customers', [CustomerLookupController::class, 'index']);
});
