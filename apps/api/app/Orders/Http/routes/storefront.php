<?php

use App\Orders\Http\Controllers\OrderController;
use App\Orders\Http\Controllers\PromoCodeCheckController;
use Illuminate\Support\Facades\Route;

/*
 * Storefront order routes (stage-07 plan, Endpoints "Buyer-facing").
 * Mounted under the v1 prefix by OrdersServiceProvider with the
 * tenancy.storefront group; unlike holds, every route here requires a
 * customer bearer token.
 */
Route::middleware('auth:customer')->group(function (): void {
    Route::post('/storefront/orders', [OrderController::class, 'store']);
    Route::get('/storefront/orders/{order}', [OrderController::class, 'show'])->whereUuid('order');
    Route::get('/storefront/orders/{order}/tickets', [OrderController::class, 'tickets'])->whereUuid('order');
    Route::post('/storefront/orders/{order}/cancel', [OrderController::class, 'cancel'])->whereUuid('order');
    Route::post('/storefront/promo-codes/check', PromoCodeCheckController::class);
});
