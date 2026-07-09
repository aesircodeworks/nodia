<?php

// Routes here are registered under /v1. The token endpoint is
// unauthenticated by nature; GET /v1/me asserts a staff bearer and nothing
// else (no X-Tenant-Id, no tenant transaction: memberships arrive with a
// later Stage 3 task and it always returns an empty list until then).

use App\Identity\Http\Controllers\CurrentUserController;
use App\Identity\Http\Controllers\StaffTokenController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/staff/token', StaffTokenController::class);

Route::middleware('auth:staff')->get('/me', CurrentUserController::class);
