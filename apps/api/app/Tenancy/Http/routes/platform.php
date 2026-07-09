<?php

// Routes here are registered under /v1 inside the tenancy.platform group:
// auth.platform first, then the platform request transaction.

use App\Tenancy\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::post('/tenants', [TenantController::class, 'store']);
Route::get('/tenants', [TenantController::class, 'index']);
Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->whereUuid('tenant');
Route::patch('/tenants/{tenant}', [TenantController::class, 'update'])->whereUuid('tenant');
