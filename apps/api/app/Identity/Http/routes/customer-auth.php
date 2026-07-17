<?php

// Routes here are registered under /v1 inside the tenancy.storefront
// group (Host resolution, no staff identity; stage-03 plan, Customer
// authentication and lifecycle, task breakdown item 13). Token issuance,
// refresh, registration, and claim request/confirm are unauthenticated
// by nature (the credential travels in the request body, or, for
// registration and claim, no credential at all); logout asserts a
// customer bearer via auth:customer, which App\Http\Middleware\
// EnforceCustomerTenantClaim (already in the tenancy.storefront group)
// checks against the host-resolved tenant ahead of Passport's own
// signature and revocation validation.

use App\Http\Middleware\RecordActivityAudit;
use App\Identity\Http\Controllers\ConfirmCustomerClaimController;
use App\Identity\Http\Controllers\CustomerLogoutController;
use App\Identity\Http\Controllers\CustomerRefreshController;
use App\Identity\Http\Controllers\CustomerTokenController;
use App\Identity\Http\Controllers\RegisterCustomerController;
use App\Identity\Http\Controllers\RequestCustomerClaimController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/customer/token', CustomerTokenController::class);
Route::post('/auth/customer/refresh', CustomerRefreshController::class);

// RecordActivityAudit (stage-03 task breakdown item 15) is attached only
// to the two routes that genuinely mutate a customer row; token issuance
// and refresh above write to the unscoped Passport oauth tables, not
// tenant-scoped domain data, so they carry no "mutating endpoint" audit
// entry the same way the platform group's oauth-adjacent routes do not.
Route::post('/customers', RegisterCustomerController::class)->middleware(RecordActivityAudit::class);
Route::post('/auth/customer/claim', RequestCustomerClaimController::class);
Route::post('/auth/customer/claim/confirm', ConfirmCustomerClaimController::class)->middleware(RecordActivityAudit::class);

Route::middleware('auth:customer')->group(function (): void {
    Route::post('/auth/customer/logout', CustomerLogoutController::class);
});
