<?php

use App\Payments\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Gateway-facing webhook ingestion (stage-08a plan, Endpoints).
 * Mounted under bare /v1 by PaymentsServiceProvider with no tenant
 * resolution and no authentication middleware: signature verification
 * inside the adapter is the gate, and everything persists under the
 * sentinel platform tenant.
 */
Route::post('/webhooks/{gateway}', WebhookController::class);
