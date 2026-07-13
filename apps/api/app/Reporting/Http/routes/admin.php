<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via ReportingServiceProvider,
// mounted under /v1 (stage-11 plan, task breakdown item 3). Populated
// task by task as each slice's controller and Data objects land: task 6
// (daily sales), task 9 (event finance), task 12 (attendance), task 16
// (exports, below).

use App\Http\Middleware\RecordActivityAudit;
use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Reporting\Http\Controllers\DailySalesController;
use App\Reporting\Http\Controllers\EventAttendanceController;
use App\Reporting\Http\Controllers\EventFinanceController;
use App\Reporting\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireCapability::class.':'.Capability::ReportsView->value)->group(function (): void {
    Route::get('/reports/daily-sales', [DailySalesController::class, 'index']);
    Route::get('/reports/event-finance', [EventFinanceController::class, 'index']);
    Route::get('/reports/attendance', [EventAttendanceController::class, 'index']);
});

// reports.export, not reports.view: export files carry customer PII
// (stage-11 plan, Endpoints: "every /v1/exports route requires
// reports.export because export files contain customer PII"). POST is
// the only mutation, so RecordActivityAudit (system-design 14.2) sits
// only on that inner route, mirroring CheckIn's own admin.php posture
// for check-in-assignments.
Route::middleware(RequireCapability::class.':'.Capability::ReportsExport->value)->group(function (): void {
    Route::get('/exports', [ExportController::class, 'index']);
    Route::get('/exports/{export}', [ExportController::class, 'show'])->whereUuid('export');
    Route::get('/exports/{export}/download', [ExportController::class, 'download'])->whereUuid('export');

    Route::middleware(RecordActivityAudit::class)->group(function (): void {
        Route::post('/exports', [ExportController::class, 'store']);
    });
});
