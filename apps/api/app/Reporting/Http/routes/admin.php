<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via ReportingServiceProvider,
// mounted under /v1 (stage-11 plan, task breakdown item 3). Populated
// task by task as each slice's controller and Data objects land: task 6
// (daily sales, below), tasks 9, 12, and 16 (event finance, attendance,
// exports).

use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Reporting\Http\Controllers\DailySalesController;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireCapability::class.':'.Capability::ReportsView->value)->group(function (): void {
    Route::get('/reports/daily-sales', [DailySalesController::class, 'index']);
});
