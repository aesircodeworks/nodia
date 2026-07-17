<?php

namespace App\Payments\Gateways;

use App\Payments\Enums\PayoutStatus;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * A gateway payout webhook, normalized at the adapter boundary
 * (stage-08c plan, Slice 5). Distinct from NormalizedSubmerchantEvent
 * because the resolution key that follows (a payout mirror row keyed
 * on gateway_reference, not a sub-merchant account) and the payload
 * shape are unrelated; both still resolve their owning tenant through
 * gatewayAccountReference (system-design 4.3), since payout callbacks
 * carry no tenant context either. amount is present only on a
 * payout.created payload (the sole moment a mirror row can be
 * inserted); a payout.status_changed payload carries null, since it
 * only ever transitions a row that must already exist.
 */
final class NormalizedPayoutEvent
{
    public function __construct(
        public readonly string $gatewayAccountReference,
        public readonly string $gatewayReference,
        public readonly PayoutStatus $status,
        public readonly ?Money $amount,
        public readonly ?CarbonImmutable $executedAt,
    ) {}
}
