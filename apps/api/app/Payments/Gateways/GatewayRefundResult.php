<?php

namespace App\Payments\Gateways;

/**
 * Acceptance means the gateway took the refund for asynchronous
 * processing; completion arrives by webhook or the reconciliation
 * sweeper. A decline is a result, never an exception.
 */
final readonly class GatewayRefundResult
{
    private function __construct(
        public bool $accepted,
        public ?string $gatewayReference,
        public ?string $failureCode,
    ) {}

    public static function accepted(string $gatewayReference): self
    {
        return new self(true, $gatewayReference, null);
    }

    public static function declined(string $failureCode): self
    {
        return new self(false, null, $failureCode);
    }
}
