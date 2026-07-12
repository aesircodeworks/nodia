<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fake Gateway
    |--------------------------------------------------------------------------
    |
    | The in-repo adapter every Stage 8a behavior runs against (master plan
    | "Payment gateway posture"). Confirmation windows are minutes per
    | async method; the fee is deterministic basis points so tests can
    | assert the exact persisted fee_amount. The webhook secret signs the
    | emitter's payloads and verifies inbound ones; any non-empty value
    | works because no external party ever calls this gateway.
    |
    */

    'gateways' => [
        'fake' => [
            'webhook_secret' => env('FAKE_GATEWAY_WEBHOOK_SECRET', 'fake-gateway-secret'),
            'fee_bps' => (int) env('FAKE_GATEWAY_FEE_BPS', 250),
            'currencies' => ['BRL', 'USD'],
            'split_support' => (bool) env('FAKE_GATEWAY_SPLIT_SUPPORT', false),
            'windows' => [
                'pix' => 30,
                'boleto' => 4320,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Low Inventory Cutoff
    |--------------------------------------------------------------------------
    |
    | Platform default for the automatic slow-method cutoff (system-design
    | 7.4): once remaining inventory for an ordered event drops to or below
    | this many units, async methods leave the offer so a slow confirmation
    | cannot strand the last tickets. Events override it through
    | async_payment_policy.low_inventory_cutoff (Stage 5a shape).
    |
    */

    'low_inventory_cutoff' => (int) env('PAYMENTS_LOW_INVENTORY_CUTOFF', 10),

    /*
    |--------------------------------------------------------------------------
    | Gateway Retry-After
    |--------------------------------------------------------------------------
    |
    | Seconds advertised in the Retry-After header of gateway_unavailable
    | problems (api-conventions Errors; system-design 7.6: no automatic
    | retry on the interactive path, the buyer is told when to try again).
    |
    */

    'gateway_retry_after_seconds' => (int) env('PAYMENTS_GATEWAY_RETRY_AFTER_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Reconciliation Grace Period
    |--------------------------------------------------------------------------
    |
    | The poller only queries the gateway for initiated payments older
    | than this many seconds (system-design 13): younger ones are still
    | expected to resolve through their webhook.
    |
    */

    'reconcile_grace_seconds' => (int) env('PAYMENTS_RECONCILE_GRACE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Per-gateway breaker thresholds (system-design 13). Consecutive
    | transport failures at or above the threshold open the breaker for
    | the cooldown; the first request after the cooldown is the half-open
    | probe. Tuned only under Stage 12 load tests.
    |
    */

    'circuit_breaker' => [
        'failure_threshold' => (int) env('PAYMENTS_BREAKER_FAILURE_THRESHOLD', 5),
        'cooldown_seconds' => (int) env('PAYMENTS_BREAKER_COOLDOWN_SECONDS', 60),
    ],

];
