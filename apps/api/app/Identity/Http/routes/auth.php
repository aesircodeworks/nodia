<?php

// Routes here are registered under /v1. The token and refresh endpoints
// are unauthenticated by nature (the credential travels in the request
// body, not a bearer header); logout, GET /v1/me, and GET /v1/capabilities
// assert a staff bearer and nothing else (no X-Tenant-Id, no tenant
// transaction). GET /v1/capabilities is a static read of the Capability
// registry, not tenant-scoped data, so it needs no membership check
// (stage-03 plan, Roles and memberships endpoint table lists only
// auth.unauthenticated for it); memberships on GET /v1/me arrive with a
// later Stage 3 task and it always returns an empty list until then.

use App\Identity\Http\Controllers\CapabilityController;
use App\Identity\Http\Controllers\CurrentUserController;
use App\Identity\Http\Controllers\StaffLogoutController;
use App\Identity\Http\Controllers\StaffRefreshController;
use App\Identity\Http\Controllers\StaffTokenController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/staff/token', StaffTokenController::class);
Route::post('/auth/staff/refresh', StaffRefreshController::class);

Route::middleware('auth:staff')->group(function (): void {
    Route::post('/auth/staff/logout', StaffLogoutController::class);
    Route::get('/me', CurrentUserController::class);
    Route::get('/capabilities', CapabilityController::class);
});
