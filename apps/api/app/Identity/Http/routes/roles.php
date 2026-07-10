<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via IdentityServiceProvider, mounted
// under /v1 (stage-03 plan, task breakdown item 8). Reading roles needs
// only a valid tenant membership; mutating them additionally requires the
// roles.manage capability, evaluated through RequireCapability the same
// way task breakdown item 7 wired tenants.manage on the platform group.

use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Identity\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::get('/roles', [RoleController::class, 'index']);
Route::get('/roles/{role}', [RoleController::class, 'show'])->whereUuid('role');

Route::middleware(RequireCapability::class.':'.Capability::RolesManage->value)->group(function (): void {
    Route::post('/roles', [RoleController::class, 'store']);
    Route::patch('/roles/{role}', [RoleController::class, 'update'])->whereUuid('role');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->whereUuid('role');
});
