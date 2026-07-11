<?php

use App\Inventory\Http\Controllers\HoldController;
use Illuminate\Support\Facades\Route;

// Registered under the tenancy.storefront group (Host resolution;
// EnforceCustomerTenantClaim already validates any bearer's tenant_id
// claim ahead of these handlers) via App\Inventory\InventoryServiceProvider
// (stage-06 plan, task breakdown item 4). Guest checkout: no
// authentication is required to create or read a hold.
Route::post('/storefront/holds', [HoldController::class, 'store']);
Route::get('/storefront/holds/{hold}', [HoldController::class, 'show'])->whereUuid('hold');
Route::delete('/storefront/holds/{hold}', [HoldController::class, 'destroy'])->whereUuid('hold');
