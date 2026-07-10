<?php

namespace App\EventCatalog\Events;

use App\EventCatalog\Models\Event;
use App\Support\Outbox\DomainEvent;
use Carbon\CarbonImmutable;

/**
 * Recorded by App\EventCatalog\Actions\PublishEvent in the same
 * transaction as the conditional UPDATE, only when its affected-row
 * count is 1 (stage-05a plan, Domain events).
 */
final readonly class EventPublished implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public EventPublishedPayload $payload,
    ) {
        $this->aggregateType = 'event';
    }

    public function type(): string
    {
        return 'EventPublished';
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

    public function payload(): EventPublishedPayload
    {
        return $this->payload;
    }

    public static function fromEvent(Event $event, CarbonImmutable $publishedAt): self
    {
        return new self(
            $event->tenant_id,
            $event->id,
            new EventPublishedPayload($event->id, $publishedAt->utc()->format('Y-m-d\TH:i:s\Z')),
        );
    }
}
