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
use App\Payments\Http\Controllers\LedgerController;
use App\Payments\Http\Controllers\PayoutController;
use App\Payments\Http\Controllers\RefundController;
use App\Payments\Http\Controllers\SubmerchantAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireCapability::class.':'.Capability::OrdersRefund->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/payments/{payment}/refunds', [RefundController::class, 'store'])->whereUuid('payment');
});

Route::middleware(RequireCapability::class.':'.Capability::OrdersView->value)->group(function (): void {
    Route::get('/refunds', [RefundController::class, 'index']);
    Route::get('/refunds/{refund}', [RefundController::class, 'show'])->whereUuid('refund');
});

Route::middleware(RequireCapability::class.':'.Capability::LedgerView->value)->group(function (): void {
    Route::get('/ledger-entries', [LedgerController::class, 'entries']);
    Route::get('/ledger-balances', [LedgerController::class, 'balances']);
});

Route::middleware([RequireCapability::class.':'.Capability::PayoutsManage->value, RecordActivityAudit::class])->group(function (): void {
    Route::post('/submerchant-accounts', [SubmerchantAccountController::class, 'store']);
    Route::post('/submerchant-accounts/{submerchant_account}/refresh', [SubmerchantAccountController::class, 'refresh'])->whereUuid('submerchant_account');
});

Route::middleware(RequireCapability::class.':'.Capability::PayoutsView->value)->group(function (): void {
    Route::get('/submerchant-accounts', [SubmerchantAccountController::class, 'index']);
    Route::get('/submerchant-accounts/{submerchant_account}', [SubmerchantAccountController::class, 'show'])->whereUuid('submerchant_account');
    Route::get('/payouts', [PayoutController::class, 'index']);
    Route::get('/payouts/{payout}', [PayoutController::class, 'show'])->whereUuid('payout');
});
