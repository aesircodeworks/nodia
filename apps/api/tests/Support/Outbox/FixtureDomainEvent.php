<?php

declare(strict_types=1);

namespace Tests\Support\Outbox;

use App\Support\Outbox\DomainEvent;

/**
 * Test-only domain event for outbox recorder coverage. Lives under tests/
 * and never enters the production registry (stage-04 plan, Registry).
 */
final readonly class FixtureDomainEvent implements DomainEvent
{
    public const TYPE = 'FixtureEvent';

    public function __construct(
        private string $tenantId,
        private string $aggregateId,
        private FixtureDomainEventPayload $payload,
        private string $aggregateType = 'fixture',
        private string $type = self::TYPE,
    ) {}

    public function type(): string
    {
        return $this->type;
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

    public function payload(): FixtureDomainEventPayload
    {
        return $this->payload;
    }
}
