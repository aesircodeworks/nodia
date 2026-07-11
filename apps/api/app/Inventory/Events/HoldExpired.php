<?php

namespace App\Inventory\Events;

use App\Inventory\Models\Hold;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Inventory\Actions\ReleaseExpiredHolds in the producing
 * transaction, exactly once, when the sweeper wins the active -> expired
 * conditional transition (stage-06 plan, Domain events: "HoldExpired |
 * Sweeper wins the active -> expired transition"). A hold that an
 * explicit release beat the sweeper to never reaches this path: the
 * sweeper's own conditional UPDATE affects zero rows for it, so exactly
 * one of HoldReleased or HoldExpired is ever recorded per hold, never
 * both.
 */
final readonly class HoldExpired implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public HoldExpiredPayload $payload,
    ) {
        $this->aggregateType = 'hold';
    }

    public function type(): string
    {
        return 'HoldExpired';
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

    public function payload(): HoldExpiredPayload
    {
        return $this->payload;
    }

    public static function fromHold(Hold $hold): self
    {
        return new self(
            $hold->tenant_id,
            $hold->id,
            HoldExpiredPayload::fromHold($hold),
        );
    }
}
