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

// GET /v1/data-subject-requests/{data_subject_request} and GET
// /v1/data-subject-requests (task breakdown item 6): read-only, so no
// RecordActivityAudit, and gated on either capability directly inside
// the controller (CapabilityGate::authorizeAny) rather than a static
// RequireCapability entry, mirroring store()'s own posture above.
Route::get('/data-subject-requests', [DataSubjectRequestController::class, 'index']);
Route::get('/data-subject-requests/{data_subject_request}', [DataSubjectRequestController::class, 'show'])->whereUuid('data_subject_request');
