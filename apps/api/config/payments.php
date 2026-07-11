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

];
