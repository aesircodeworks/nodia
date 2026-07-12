<?php

namespace App\Payments\Gateways;

use App\Payments\Enums\SubmerchantStatus;

/**
 * A gateway sub-merchant status webhook, normalized at the adapter
 * boundary (stage-08c plan, Slice 3). Distinct from
 * NormalizedPaymentEvent because the payload shape and downstream
 * resolution (by gateway_account_reference, not a payment/refund
 * reference) are unrelated.
 */
final class NormalizedSubmerchantEvent
{
    /**
     * @param  list<string>  $requirements
     */
    public function __construct(
        public readonly string $gatewayAccountReference,
        public readonly SubmerchantStatus $status,
        public readonly array $requirements,
    ) {}
}
