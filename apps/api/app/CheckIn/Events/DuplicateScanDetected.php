<?php

namespace App\CheckIn\Events;

use App\Support\Outbox\DomainEvent;

/**
 * Recorded once per duplicate check_ins row insert by App\CheckIn\
 * Actions\RecordScan (online path) and, from stage-09 Task 11 onward,
 * batch reconciliation swaps (stage-09 plan, Domain events "Produced";
 * system-design 9.3 group 6, system-design 11 "flag, never drop").
 */
final readonly class DuplicateScanDetected implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public DuplicateScanDetectedPayload $payload,
    ) {
        $this->aggregateType = 'ticket';
    }

    public function type(): string
    {
        return 'DuplicateScanDetected';
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

    public function payload(): DuplicateScanDetectedPayload
    {
        return $this->payload;
    }
}
