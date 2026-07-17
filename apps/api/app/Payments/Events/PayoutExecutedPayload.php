<?php

namespace App\Payments\Events;

use App\Payments\Models\Payout;
use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for PayoutExecuted (stage-08c plan, Domain
 * events): identifiers and facts only, no entity snapshot
 * (event-conventions).
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class PayoutExecutedPayload extends Data
{
    public function __construct(
        public string $payoutId,
        public string $gateway,
        public string $gatewayReference,
        public Money $amount,
        public string $executedAt,
    ) {}

    public static function fromPayout(Payout $payout): self
    {
        return new self(
            $payout->id,
            $payout->gateway,
            $payout->gateway_reference,
            $payout->money,
            $payout->executed_at->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
