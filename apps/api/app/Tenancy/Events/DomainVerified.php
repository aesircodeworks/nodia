<?php

namespace App\Tenancy\Events;

use App\Support\Outbox\DomainEvent;
use App\Tenancy\Models\TenantDomain;

/**
 * Envelope per event-conventions: the envelope tenant_id is the owning
 * tenant, the aggregate is the tenant domain. Recorded by RegisterDomain
 * in the same transaction as the row insert. The settled trigger is
 * registration: system-design 16.3 gates TLS issuance on existence alone,
 * and registration through the audited platform-admin surface is the
 * platform's act of verification. There is no verified_at column and no
 * challenge flow; if a later stage adds tenant self-service domain
 * registration, its verification flow gets a new event type.
 */
final readonly class DomainVerified implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public DomainVerifiedPayload $payload,
    ) {
        $this->aggregateType = 'tenant_domain';
    }

    public function type(): string
    {
        return 'DomainVerified';
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

    public function payload(): DomainVerifiedPayload
    {
        return $this->payload;
    }

    public static function fromTenantDomain(TenantDomain $domain): self
    {
        return new self(
            $domain->tenant_id,
            $domain->id,
            new DomainVerifiedPayload($domain->id, $domain->tenant_id, $domain->domain),
        );
    }
}
