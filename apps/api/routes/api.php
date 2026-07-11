<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MediaController;
use App\Http\Middleware\RecordActivityAudit;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);
});

// DELETE /v1/media/{media} (stage-05c plan, Endpoints): a top-level route
// with no single owning bounded context (App\Http\Controllers\MediaController's
// own docblock), so it lives here directly rather than under a context's
// own Http\routes, mirroring the health route above. tenancy.admin carries
// the same staff bearer, X-Tenant-Id membership, and MFA-compliance
// posture every other admin mutation runs under; RecordActivityAudit
// records the mutation the same way roles.php/memberships.php's own
// mutating routes do. There is no RequireCapability parameter here: which
// capability applies depends on the media row's own owning model, decided
// inside the controller, not by this route.
Route::middleware(['tenancy.admin', RecordActivityAudit::class])->prefix('v1')->group(function () {
    Route::delete('media/{media}', [MediaController::class, 'destroy'])->whereUuid('media');
});
