<?php

namespace App\EventCatalog\Events;

use App\EventCatalog\Models\Event;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\EventCatalog\Actions\CreateEvent (stage-05a plan,
 * Domain events, task breakdown item 5), mirroring
 * App\Tenancy\Events\TenantCreated's own envelope shape.
 */
final readonly class EventCreated implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public EventCreatedPayload $payload,
    ) {
        $this->aggregateType = 'event';
    }

    public function type(): string
    {
        return 'EventCreated';
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

    public function payload(): EventCreatedPayload
    {
        return $this->payload;
    }

    public static function fromEvent(Event $event): self
    {
        return new self($event->tenant_id, $event->id, new EventCreatedPayload($event->id));
    }
}
