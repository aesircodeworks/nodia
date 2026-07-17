<?php

namespace App\Inventory\Events;

use App\Inventory\Models\Hold;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Inventory\Actions\ReleaseHold in the producing
 * transaction, exactly once, when an explicit release wins the
 * active -> released conditional transition (stage-06 plan, Domain
 * events: "HoldReleased | Explicit release (buyer or Orders) commits").
 * Never recorded for a hold that was already released, expired, or
 * committed: those paths are idempotent no-ops that record nothing.
 */
final readonly class HoldReleased implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public HoldReleasedPayload $payload,
    ) {
        $this->aggregateType = 'hold';
    }

    public function type(): string
    {
        return 'HoldReleased';
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

    public function payload(): HoldReleasedPayload
    {
        return $this->payload;
    }

    /**
     * @param  list<string>  $seatIds
     */
    public static function fromHold(Hold $hold, array $seatIds = []): self
    {
        return new self(
            $hold->tenant_id,
            $hold->id,
            HoldReleasedPayload::fromHold($hold, $seatIds),
        );
    }
}
