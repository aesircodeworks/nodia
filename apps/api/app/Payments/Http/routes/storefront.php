<?php

use App\Payments\Http\Controllers\PaymentMethodOfferController;
use Illuminate\Support\Facades\Route;

/*
 * Storefront payment routes (stage-08a plan, Endpoints). Mounted under
 * the v1 prefix by PaymentsServiceProvider with the tenancy.storefront
 * group; every route requires a customer bearer token bound to the
 * resolved tenant.
 */
Route::middleware('auth:customer')->group(function (): void {
    Route::get('/storefront/orders/{order}/payment-methods', PaymentMethodOfferController::class)->whereUuid('order');
});
