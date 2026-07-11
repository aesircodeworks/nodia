<?php

namespace App\Orders\Events;

use App\Orders\Models\Ticket;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Orders\Actions\IssueTickets inside the paid
 * transition transaction, one event per ticket (stage-07 plan, Domain
 * events).
 */
final readonly class TicketIssued implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public TicketIssuedPayload $payload,
    ) {
        $this->aggregateType = 'ticket';
    }

    public function type(): string
    {
        return 'TicketIssued';
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

    public function payload(): TicketIssuedPayload
    {
        return $this->payload;
    }

    public static function fromTicket(Ticket $ticket): self
    {
        return new self($ticket->tenant_id, $ticket->id, TicketIssuedPayload::fromTicket($ticket));
    }
}
