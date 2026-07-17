<?php

// Routes here are registered under /v1 inside the tenancy.platform group:
// Passport staff bearer, then the platform request transaction, then the
// tenants.manage capability check (stage-03 plan, task breakdown item 7).

use App\Tenancy\Http\Controllers\TenantController;
use App\Tenancy\Http\Controllers\TenantDomainController;
use App\Tenancy\Http\Controllers\TenantMediaController;
use Illuminate\Support\Facades\Route;

Route::post('/tenants', [TenantController::class, 'store']);
Route::get('/tenants', [TenantController::class, 'index']);
Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->whereUuid('tenant');
Route::patch('/tenants/{tenant}', [TenantController::class, 'update'])->whereUuid('tenant');

// stage-05c plan, Endpoints: "Admin tenant branding media". Deletion is
// the shared top-level DELETE /v1/media/{media} (routes/api.php), not
// nested here.
Route::post('/tenants/{tenant}/media', [TenantMediaController::class, 'store'])->whereUuid('tenant');

Route::post('/tenants/{tenant}/domains', [TenantDomainController::class, 'store'])->whereUuid('tenant');
Route::get('/tenants/{tenant}/domains', [TenantDomainController::class, 'index'])->whereUuid('tenant');

// Domain item operations live at top level because nesting them under
// /v1/tenants/{tenant}/domains/{domain} would exceed the one-level nesting
// rule (api-conventions, URLs).
Route::patch('/tenant-domains/{tenant_domain}', [TenantDomainController::class, 'update'])->whereUuid('tenant_domain');
Route::delete('/tenant-domains/{tenant_domain}', [TenantDomainController::class, 'destroy'])->whereUuid('tenant_domain');
