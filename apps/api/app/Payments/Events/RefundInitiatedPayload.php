<?php

namespace App\Payments\Events;

use App\Payments\Models\Refund;
use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for RefundInitiated (stage-08b plan, Domain
 * events). payment_id is the ordering key the ledger projection groups
 * on; the money breakdown rides the payload so downstream consumers
 * stay free of cross-context lookups.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class RefundInitiatedPayload extends Data
{
    /**
     * @param  list<string>|null  $ticketIds
     */
    public function __construct(
        public string $refundId,
        public string $paymentId,
        public string $orderId,
        public Money $amount,
        public Money $commissionAmount,
        public ?array $ticketIds,
        public ?string $reason,
    ) {}

    public static function fromRefund(Refund $refund, string $orderId): self
    {
        return new self(
            $refund->id,
            $refund->payment_id,
            $orderId,
            $refund->money,
            Money::of($refund->commission_amount, $refund->currency),
            $refund->ticket_ids,
            $refund->reason,
        );
    }
}
