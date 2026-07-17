<?php

namespace App\Payments\Gateways;

/**
 * What the platform sends a gateway to start Sub-merchant onboarding
 * (system-design 7.3). The gateway owns the KYC flow; this carries only
 * the settlement facts it needs to start one.
 */
final class SubmerchantRegistrationRequest
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $settlementCurrency,
        public readonly string $payoutSchedule,
    ) {}
}
