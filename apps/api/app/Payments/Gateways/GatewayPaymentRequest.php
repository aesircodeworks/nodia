<?php

namespace App\Payments\Gateways;

use App\Support\Money\Money;

/**
 * The server-generated gateway idempotency key is the payment row's UUID
 * (system-design 7.5): globally unique, so two tenants supplying the same
 * Idempotency-Key header never collide at the gateway. The client header
 * scopes API replay only and is never forwarded here.
 */
final class GatewayPaymentRequest
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly string $method,
        public readonly Money $amount,
        public readonly array $details = [],
    ) {}
}
