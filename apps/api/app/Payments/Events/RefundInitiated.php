<?php

namespace App\Payments\Events;

use App\Payments\Models\Refund;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Payments\Actions\CreateRefund in the same transaction
 * as the refund insert and the refundable-amount reservation (stage-08b
 * plan, Domain events). The executor consumer calls the gateway from
 * this event.
 */
final readonly class RefundInitiated implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public RefundInitiatedPayload $payload,
    ) {
        $this->aggregateType = 'refund';
    }

    public function type(): string
    {
        return 'RefundInitiated';
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function aggregateType(): string
    {
        return $this->aggregateType;
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function payload(): RefundInitiatedPayload
    {
        return $this->payload;
    }

    public static function fromRefund(Refund $refund, string $orderId): self
    {
        return new self($refund->tenant_id, $refund->id, RefundInitiatedPayload::fromRefund($refund, $orderId));
    }
}
