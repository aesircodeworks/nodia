<?php

namespace App\Payments\Gateways;

use App\Payments\Enums\PayoutStatus;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * A gateway payout object as returned by listPayouts, the reconciliation
 * poller's backstop for missed payout webhooks (stage-08c plan, Slice 5;
 * system-design 8.3, 13).
 */
final class GatewayPayoutRecord
{
    public function __construct(
        public readonly string $gatewayReference,
        public readonly Money $amount,
        public readonly PayoutStatus $status,
        public readonly ?CarbonImmutable $executedAt,
    ) {}
}
