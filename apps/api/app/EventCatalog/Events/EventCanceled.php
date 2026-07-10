<?php

namespace App\EventCatalog\Events;

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\Support\Outbox\DomainEvent;
use Carbon\CarbonImmutable;

/**
 * Recorded by App\EventCatalog\Actions\CancelEvent in the same
 * transaction as the conditional UPDATE, only when its affected-row
 * count is 1 (stage-05a plan, Domain events).
 */
final readonly class EventCanceled implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public EventCanceledPayload $payload,
    ) {
        $this->aggregateType = 'event';
    }

    public function type(): string
    {
        return 'EventCanceled';
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

    public function payload(): EventCanceledPayload
    {
        return $this->payload;
    }

    public static function fromEvent(Event $event, EventStatus $priorStatus, CarbonImmutable $canceledAt): self
    {
        return new self(
            $event->tenant_id,
            $event->id,
            new EventCanceledPayload($event->id, $canceledAt->utc()->format('Y-m-d\TH:i:s\Z'), $priorStatus->value),
        );
    }
}
