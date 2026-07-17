<?php

namespace App\Identity\Events;

use App\Identity\Models\Customer;
use App\Support\Outbox\DomainEvent;

/**
 * Envelope per event-conventions and stage-04 Domain events: aggregate is
 * the customer, envelope tenant_id is the customer's tenant.
 */
final readonly class CustomerRegistered implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public CustomerRegisteredPayload $payload,
    ) {
        $this->aggregateType = 'customer';
    }

    public function type(): string
    {
        return 'CustomerRegistered';
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

    public function payload(): CustomerRegisteredPayload
    {
        return $this->payload;
    }

    public static function fromCustomer(Customer $customer): self
    {
        return new self(
            $customer->tenant_id,
            $customer->id,
            new CustomerRegisteredPayload(
                $customer->id,
                $customer->password === null,
            ),
        );
    }
}
