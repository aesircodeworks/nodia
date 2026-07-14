<?php

use App\Payments\Http\Controllers\PaymentController;
use App\Payments\Http\Controllers\PaymentMethodOfferController;
use Illuminate\Support\Facades\Route;

/*
 * Storefront payment routes (stage-08a plan, Endpoints). Mounted under
 * the v1 prefix by PaymentsServiceProvider with the tenancy.storefront
 * group; every route requires a customer bearer token bound to the
 * resolved tenant.
 *
 * The POST carries no RecordActivityAudit: a synchronous decline commits
 * a failed payment while answering 402, which that success-only
 * middleware would skip, so PaymentController records the financial
 * activity-log entry itself across every outcome (system-design 14.2).
 */
Route::middleware('auth:customer')->group(function (): void {
    Route::get('/storefront/orders/{order}/payment-methods', PaymentMethodOfferController::class)->whereUuid('order');
    Route::post('/storefront/orders/{order}/payments', [PaymentController::class, 'store'])->whereUuid('order');
    Route::get('/storefront/payments/{payment}', [PaymentController::class, 'show'])->whereUuid('payment');
});
