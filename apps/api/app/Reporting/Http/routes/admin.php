<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via ReportingServiceProvider,
// mounted under /v1 (stage-11 plan, task breakdown item 3). Populated
// task by task as each slice's controller and Data objects land: task 6
// (daily sales), task 9 (event finance), task 12 (attendance, below),
// task 16 (exports).

use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Reporting\Http\Controllers\DailySalesController;
use App\Reporting\Http\Controllers\EventAttendanceController;
use App\Reporting\Http\Controllers\EventFinanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireCapability::class.':'.Capability::ReportsView->value)->group(function (): void {
    Route::get('/reports/daily-sales', [DailySalesController::class, 'index']);
    Route::get('/reports/event-finance', [EventFinanceController::class, 'index']);
    Route::get('/reports/attendance', [EventAttendanceController::class, 'index']);
});
