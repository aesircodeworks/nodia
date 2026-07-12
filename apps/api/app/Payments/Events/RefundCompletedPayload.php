<?php

namespace App\Payments\Events;

use App\Payments\Enums\RefundCommissionPolicy;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for RefundCompleted (stage-08b plan, Domain
 * events): everything RefundInitiated carries plus the gateway
 * reference and the commission policy, so downstream consumers
 * (ledger projection, Stage 11 reporting) stay free of cross-context
 * lookups. The policy is derived from the persisted commission row
 * fact, not live tenant configuration, keeping replay deterministic.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class RefundCompletedPayload extends Data
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
        public ?string $gatewayReference,
        public RefundCommissionPolicy $commissionPolicy,
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
            $refund->gateway_reference,
            $refund->commission_policy,
        );
    }
}
