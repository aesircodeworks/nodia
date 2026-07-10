<?php

namespace App\EventCatalog\Events;

use App\EventCatalog\Models\Event;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\EventCatalog\Actions\UpdateEvent on every successful
 * PATCH (stage-05a plan, Domain events); task breakdown item 8's
 * CreateTicketType and UpdateTicketType also record this same event type
 * for the parent event's sellable-surface change, per the plan's own
 * "the registry has no ticket-type event" note.
 */
final readonly class EventUpdated implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public EventUpdatedPayload $payload,
    ) {
        $this->aggregateType = 'event';
    }

    public function type(): string
    {
        return 'EventUpdated';
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

    public function payload(): EventUpdatedPayload
    {
        return $this->payload;
    }

    public static function fromEvent(Event $event): self
    {
        return new self($event->tenant_id, $event->id, new EventUpdatedPayload($event->id));
    }
}
