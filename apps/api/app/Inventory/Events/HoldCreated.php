<?php

namespace App\Inventory\Events;

use App\Inventory\Models\Hold;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Inventory\Actions\CreateHold in the producing
 * transaction, exactly once, when a hold is created (stage-06 plan,
 * Domain events: "HoldCreated | CreateHold commits").
 */
final readonly class HoldCreated implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public HoldCreatedPayload $payload,
    ) {
        $this->aggregateType = 'hold';
    }

    public function type(): string
    {
        return 'HoldCreated';
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

    public function payload(): HoldCreatedPayload
    {
        return $this->payload;
    }

    public static function fromHold(Hold $hold): self
    {
        return new self(
            $hold->tenant_id,
            $hold->id,
            HoldCreatedPayload::fromHold($hold),
        );
    }
}
