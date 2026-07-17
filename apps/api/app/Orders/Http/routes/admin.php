<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via OrdersServiceProvider, mounted
// under /v1 (stage-07 plan, Endpoints "Staff-facing"). Everything here
// is capability-gated; mutations additionally ride RecordActivityAudit.

use App\Http\Middleware\RecordActivityAudit;
use App\Http\Middleware\RequireCapability;
use App\Identity\Capability;
use App\Orders\Http\Controllers\PromoCodeAdminController;
use App\Orders\Http\Controllers\SigningKeyController;
use App\Orders\Http\Controllers\StaffOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireCapability::class.':'.Capability::OrdersView->value)->group(function (): void {
    Route::get('/orders', [StaffOrderController::class, 'index']);
    Route::get('/orders/{order}', [StaffOrderController::class, 'show'])->whereUuid('order');
});

Route::middleware([RequireCapability::class.':'.Capability::OrdersResendTickets->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/orders/{order}/resend-tickets', [StaffOrderController::class, 'resendTickets'])->whereUuid('order');
});

// GET has no single-capability route gate: authorization is
// checkin.scan plus an event assignment, or checkin.manage as a bypass,
// which only App\Orders\Http\Controllers\SigningKeyController's own
// call to CheckEventAssignment can evaluate (stage-09 plan, Endpoints
// "GET /v1/events/{event}/signing-keys").
Route::get('/events/{event}/signing-keys', [SigningKeyController::class, 'index'])->whereUuid('event');

Route::middleware([RequireCapability::class.':'.Capability::CheckinManage->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/events/{event}/signing-keys', [SigningKeyController::class, 'store'])->whereUuid('event');
});

Route::middleware(RequireCapability::class.':'.Capability::PromoCodesManage->value)->group(function (): void {
    Route::get('/promo-codes', [PromoCodeAdminController::class, 'index']);
    Route::get('/promo-codes/{promo_code}', [PromoCodeAdminController::class, 'show'])->whereUuid('promo_code');

    Route::middleware(RecordActivityAudit::class)->group(function (): void {
        Route::post('/promo-codes', [PromoCodeAdminController::class, 'store']);
        Route::patch('/promo-codes/{promo_code}', [PromoCodeAdminController::class, 'update'])->whereUuid('promo_code');
    });
});
