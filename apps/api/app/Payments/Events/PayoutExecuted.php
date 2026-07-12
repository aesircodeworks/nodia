<?php

namespace App\Payments\Events;

use App\Payments\Models\Payout;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Payments\Actions\RecordGatewayPayout in the same
 * transaction as the payout's conditional transition to paid, and only
 * on that transition (stage-08c plan, Domain events); a payout landing
 * on failed or canceled records no domain event.
 */
final readonly class PayoutExecuted implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public PayoutExecutedPayload $payload,
    ) {
        $this->aggregateType = 'payout';
    }

    public function type(): string
    {
        return 'PayoutExecuted';
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

    public function payload(): PayoutExecutedPayload
    {
        return $this->payload;
    }

    public static function fromPayout(Payout $payout): self
    {
        return new self($payout->tenant_id, $payout->id, PayoutExecutedPayload::fromPayout($payout));
    }
}
