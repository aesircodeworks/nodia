<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via IdentityServiceProvider, mounted
// under /v1 (stage-07 plan, task breakdown item 10). Read-only and
// capability-gated: the customer lookup exposes PII, so unlike roles and
// memberships reads it requires customers.view rather than bare
// membership.

use App\Http\Middleware\RecordActivityAudit;
use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Identity\Http\Controllers\CustomerLookupController;
use App\Identity\Http\Controllers\DataSubjectRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireCapability::class.':'.Capability::CustomersView->value)->group(function (): void {
    Route::get('/customers', [CustomerLookupController::class, 'index']);
});

// The capability required (customers.erase or customers.export) depends
// on the request body's own type, so this route carries no
// RequireCapability entry: App\Identity\Http\Controllers\
// DataSubjectRequestController calls CapabilityGate directly instead
// (stage-12 plan, Endpoints). RecordActivityAudit still applies: it only
// logs a response that actually succeeded, so a 403 or 409 records
// nothing.
Route::middleware(RecordActivityAudit::class)->group(function (): void {
    Route::post('/customers/{customer}/data-subject-requests', [DataSubjectRequestController::class, 'store'])->whereUuid('customer');
});
