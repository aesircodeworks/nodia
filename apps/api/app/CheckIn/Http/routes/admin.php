<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via CheckInServiceProvider, mounted
// under /v1 (stage-09 plan, Endpoints). GET has no single-capability
// route gate: authorization is checkin.scan plus an event assignment, or
// checkin.manage as a bypass, which only the controller's own call to
// CheckEventAssignment can evaluate, mirroring
// App\Orders\Http\routes\admin.php's own signing-keys GET.

use App\CheckIn\Http\Controllers\CheckInAssignmentController;
use App\CheckIn\Http\Controllers\CheckInManifestController;
use App\CheckIn\Http\Controllers\ReconcileOfflineScansController;
use App\CheckIn\Http\Controllers\RecordScanController;
use App\Http\Middleware\RecordActivityAudit;
use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use Illuminate\Support\Facades\Route;

Route::get('/events/{event}/check-in-manifest', [CheckInManifestController::class, 'index'])->whereUuid('event');

// POST /v1/check-ins has no {event} route param and no single-capability
// gate either: the target event is only known once App\CheckIn\Actions\
// RecordScan has verified the QR payload, so that Action performs the
// checkin.scan-plus-assignment (or checkin.manage bypass) authorization
// itself, mirroring the GET routes' own custom-authorized posture.
//
// Deliberately not wrapped in RecordActivityAudit: a duplicate scan
// commits its check_ins row and its DuplicateScanDetected event while
// answering 409, and that middleware only records successful responses,
// so it would leave exactly the scan most worth auditing out of the
// trail. RecordScanController records the entry itself instead, for the
// accepted and duplicate outcomes alike.
Route::post('/check-ins', [RecordScanController::class, 'store']);

// POST /v1/check-in-batches has the same custom-authorized posture as
// POST /v1/check-ins, evaluated per scan since each scan's target event
// is only known once its own QR payload has verified. Unlike the single
// scan above it always answers 200 (per-scan outcomes never fail the
// batch wholesale), so the success-only middleware does record every
// reconciled batch and no controller-level entry is needed.
Route::middleware(RecordActivityAudit::class)->group(function (): void {
    Route::post('/check-in-batches', [ReconcileOfflineScansController::class, 'store']);
});

// Check-in assignments (stage-09 plan, Endpoints "Check-in assignments"
// and Slice 6): entirely behind checkin.manage, mutations audited,
// mirroring memberships.php's own capability-gated CRUD shape. DELETE
// is a top-level resource (api-conventions, URLs) so it is not nested
// under /events/{event}.
Route::middleware(RequireCapability::class.':'.Capability::CheckinManage->value)->group(function (): void {
    Route::get('/events/{event}/check-in-assignments', [CheckInAssignmentController::class, 'index'])->whereUuid('event');

    Route::middleware(RecordActivityAudit::class)->group(function (): void {
        Route::post('/events/{event}/check-in-assignments', [CheckInAssignmentController::class, 'store'])->whereUuid('event');
        Route::delete('/check-in-assignments/{assignment}', [CheckInAssignmentController::class, 'destroy'])->whereUuid('assignment');
    });
});
