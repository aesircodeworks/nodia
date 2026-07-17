<?php

namespace App\Support\Outbox;

use Spatie\LaravelData\Data;

/**
 * Contract every domain event recorded into the transactional outbox must
 * expose (event-conventions envelope fields owned by the producer). The
 * recorder assigns id, sequence, correlation_id, and occurred_at; the
 * event supplies type, tenant, aggregate coordinates, and payload.
 *
 * Concrete event classes live in the owning context's Events/ directory
 * and are recorded only by that context. Support\Outbox never imports
 * those classes: the registry is a set of type-name strings, and this
 * interface is the only type the recorder depends on.
 */
interface DomainEvent
{
    /**
     * Registry type name: past-tense fact (event-conventions Naming).
     */
    public function type(): string;

    public function tenantId(): string;

    public function aggregateType(): string;

    public function aggregateId(): string;

    public function payload(): Data;
}
