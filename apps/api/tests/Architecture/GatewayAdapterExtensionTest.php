<?php

/*
 * Stage-08c plan, Slice 1: the sub-merchant and payout operations added
 * to GatewayAdapter, and the value objects they carry, are Payments-
 * internal gateway seam, not part of any other context's surface
 * (mirrors ContextBoundariesTest's Models/Http/Events rules).
 */

arch('the Payments gateway seam is only used within the Payments context')
    ->expect('App\Payments\Gateways')
    ->toOnlyBeUsedIn('App\Payments');

arch('sub-merchant and payout gateway value objects live in App\Payments\Gateways')
    ->expect([
        'App\Payments\Gateways\SubmerchantRegistrationRequest',
        'App\Payments\Gateways\GatewaySubmerchantResult',
        'App\Payments\Gateways\GatewayPayoutRecord',
    ])
    ->toBeClasses()
    ->toHaveMethods(['__construct']);
