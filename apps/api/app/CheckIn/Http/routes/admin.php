<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via CheckInServiceProvider, mounted
// under /v1 (stage-09 plan, Endpoints). GET has no single-capability
// route gate: authorization is checkin.scan plus an event assignment, or
// checkin.manage as a bypass, which only the controller's own call to
// CheckEventAssignment can evaluate, mirroring
// App\Orders\Http\routes\admin.php's own signing-keys GET.

use App\CheckIn\Http\Controllers\CheckInManifestController;
use Illuminate\Support\Facades\Route;

Route::get('/events/{event}/check-in-manifest', [CheckInManifestController::class, 'index']);
