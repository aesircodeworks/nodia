<?php

namespace App\Orders\Events;

use App\Support\Outbox\DomainEvent;

/**
 * Defined in Stage 7 for a complete Orders event surface; not recorded
 * in this stage. Stage 8b's refund execution ships the first producer
 * and registers the type (stage-07 plan, Domain events "Produced").
 */
final readonly class TicketRefunded implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public TicketRefundedPayload $payload,
    ) {
        $this->aggregateType = 'ticket';
    }

    public function type(): string
    {
        return 'TicketRefunded';
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

    public function payload(): TicketRefundedPayload
    {
        return $this->payload;
    }
}
