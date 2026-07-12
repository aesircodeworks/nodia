<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via PaymentsServiceProvider,
// mounted under /v1 (stage-08b plan, Endpoints). orders.refund is
// financially privileged, so the group's EnforceMfaCompliance requires
// a confirmed MFA session; mutations additionally ride
// RecordActivityAudit (system-design 14.2).

use App\Http\Middleware\RecordActivityAudit;
use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Payments\Http\Controllers\RefundController;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireCapability::class.':'.Capability::OrdersRefund->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/payments/{payment}/refunds', [RefundController::class, 'store'])->whereUuid('payment');
});
