<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via IdentityServiceProvider, mounted
// under /v1 (stage-03 plan, task breakdown item 9). Reading needs only a
// valid tenant membership, mirroring roles.php; mutating additionally
// requires the memberships.manage capability. RecordActivityAudit
// (stage-03 task breakdown item 15) sits alongside RequireCapability in
// the same mutating-only inner group, mirroring roles.php.

use App\Http\Middleware\RecordActivityAudit;
use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Identity\Http\Controllers\MembershipController;
use Illuminate\Support\Facades\Route;

Route::get('/memberships', [MembershipController::class, 'index']);

Route::middleware([RequireCapability::class.':'.Capability::MembershipsManage->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/memberships', [MembershipController::class, 'store']);
    Route::patch('/memberships/{membership}', [MembershipController::class, 'update'])->whereUuid('membership');
    Route::delete('/memberships/{membership}', [MembershipController::class, 'destroy'])->whereUuid('membership');
});
