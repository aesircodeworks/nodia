<?php

namespace App\Payments\Gateways;

use App\Support\Money\Money;

final class NormalizedPaymentEvent
{
    private function __construct(
        public readonly WebhookKind $kind,
        public readonly string $gatewayReference,
        public readonly ?Money $fee,
        public readonly ?string $failureCode,
    ) {}

    public static function confirmed(string $gatewayReference, Money $fee): self
    {
        return new self(WebhookKind::Confirmed, $gatewayReference, $fee, null);
    }

    public static function failed(string $gatewayReference, string $failureCode): self
    {
        return new self(WebhookKind::Failed, $gatewayReference, null, $failureCode);
    }
}
