<?php

namespace App\CheckIn\Events;

use App\Support\Outbox\DomainEvent;

/**
 * Recorded exactly once per ticket by App\CheckIn\Actions\RecordScan, in
 * the same transaction as the accepted check_ins insert; the partial
 * unique index on check_ins guarantees at most one accepted row per
 * ticket ever commits, so at most one TicketCheckedIn is ever recorded
 * for that ticket (stage-09 plan, Domain events "Produced"; system-design
 * 9.3 group 6). Feeds Stage 11 attendance projections.
 */
final readonly class TicketCheckedIn implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public TicketCheckedInPayload $payload,
    ) {
        $this->aggregateType = 'ticket';
    }

    public function type(): string
    {
        return 'TicketCheckedIn';
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

    public function payload(): TicketCheckedInPayload
    {
        return $this->payload;
    }
}
