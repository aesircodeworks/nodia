<?php

namespace App\Payments\Events;

use App\Payments\Models\Refund;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Payments\Actions\CompleteRefund in the same
 * transaction as the processing to completed transition, the order
 * transition, and the ticket voiding (stage-08b plan, Domain events).
 */
final readonly class RefundCompleted implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public RefundCompletedPayload $payload,
    ) {
        $this->aggregateType = 'refund';
    }

    public function type(): string
    {
        return 'RefundCompleted';
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

    public function payload(): RefundCompletedPayload
    {
        return $this->payload;
    }

    public static function fromRefund(Refund $refund, string $orderId): self
    {
        return new self($refund->tenant_id, $refund->id, RefundCompletedPayload::fromRefund($refund, $orderId));
    }
}
