<?php

namespace App\Payments\Gateways;

use App\Payments\Enums\PayoutStatus;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * A gateway payout object as returned by listPayouts, the reconciliation
 * poller's backstop for missed payout webhooks (stage-08c plan, Slice 5,
 * Slice 6; system-design 8.3, 13). Carries gatewayAccountReference,
 * mirroring NormalizedPayoutEvent, because the poller resolves the
 * owning tenant through the sub-merchant account exactly as the webhook
 * path does (system-design 4.3): listPayouts callbacks carry no tenant
 * context either.
 */
final class GatewayPayoutRecord
{
    public function __construct(
        public readonly string $gatewayAccountReference,
        public readonly string $gatewayReference,
        public readonly Money $amount,
        public readonly PayoutStatus $status,
        public readonly ?CarbonImmutable $executedAt,
    ) {}
}
