<?php

namespace App\Payments\Gateways;

use App\Payments\Data\NextActionData;
use App\Support\Money\Money;

final class GatewayPaymentResult
{
    private function __construct(
        public readonly GatewayPaymentOutcome $outcome,
        public readonly string $gatewayReference,
        public readonly NextActionData $nextAction,
        public readonly ?Money $fee,
        public readonly ?string $failureCode,
    ) {}

    public static function approved(string $gatewayReference, Money $fee): self
    {
        return new self(GatewayPaymentOutcome::Approved, $gatewayReference, NextActionData::none(), $fee, null);
    }

    public static function declined(string $gatewayReference, string $failureCode): self
    {
        return new self(GatewayPaymentOutcome::Declined, $gatewayReference, NextActionData::none(), null, $failureCode);
    }

    public static function pending(string $gatewayReference, NextActionData $nextAction): self
    {
        return new self(GatewayPaymentOutcome::Pending, $gatewayReference, $nextAction, null, null);
    }
}
