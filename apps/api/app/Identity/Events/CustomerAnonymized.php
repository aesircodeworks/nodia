<?php

namespace App\Identity\Events;

use App\Support\Outbox\DomainEvent;

/**
 * Envelope per event-conventions and stage-12 plan Domain events: aggregate
 * is the customer, envelope tenant_id is the customer's tenant. Recorded by
 * App\Identity\Actions\AnonymizeCustomer in the same transaction as the
 * customer UPDATE, and only when its affected-row count is 1, so a repeat
 * or lost race never records a second event. Added to the system-design
 * 9.3 registry (Identity group) in the same change.
 */
final readonly class CustomerAnonymized implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public CustomerAnonymizedPayload $payload,
    ) {
        $this->aggregateType = 'customer';
    }

    public function type(): string
    {
        return 'CustomerAnonymized';
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

    public function payload(): CustomerAnonymizedPayload
    {
        return $this->payload;
    }

    public static function forCustomer(string $tenantId, string $customerId, string $dataSubjectRequestId): self
    {
        return new self(
            $tenantId,
            $customerId,
            new CustomerAnonymizedPayload($customerId, $dataSubjectRequestId),
        );
    }
}
