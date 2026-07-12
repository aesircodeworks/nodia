<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via CheckInServiceProvider, mounted
// under /v1 (stage-09 plan, Endpoints). GET has no single-capability
// route gate: authorization is checkin.scan plus an event assignment, or
// checkin.manage as a bypass, which only the controller's own call to
// CheckEventAssignment can evaluate, mirroring
// App\Orders\Http\routes\admin.php's own signing-keys GET.

use App\CheckIn\Http\Controllers\CheckInManifestController;
use App\CheckIn\Http\Controllers\RecordScanController;
use Illuminate\Support\Facades\Route;

Route::get('/events/{event}/check-in-manifest', [CheckInManifestController::class, 'index']);

// POST /v1/check-ins has no {event} route param and no single-capability
// gate either: the target event is only known once App\CheckIn\Actions\
// RecordScan has verified the QR payload, so that Action performs the
// checkin.scan-plus-assignment (or checkin.manage bypass) authorization
// itself, mirroring the GET routes' own custom-authorized posture.
Route::post('/check-ins', [RecordScanController::class, 'store']);
