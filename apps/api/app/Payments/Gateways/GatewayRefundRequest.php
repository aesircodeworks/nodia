<?php

namespace App\Payments\Gateways;

use App\Support\Money\Money;

/**
 * The refund half of the gateway seam (system-design 7.2, stage-08b
 * plan). idempotencyKey is the refund's own key, passed on every
 * attempt so gateway-side retries never double-refund.
 */
final readonly class GatewayRefundRequest
{
    public function __construct(
        public string $refundId,
        public string $paymentGatewayReference,
        public Money $amount,
        public string $idempotencyKey,
    ) {}
}
