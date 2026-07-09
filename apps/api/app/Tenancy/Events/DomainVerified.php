<?php

namespace App\Tenancy\Events;

use App\Tenancy\Models\TenantDomain;

/**
 * Envelope per event-conventions: the envelope tenant_id is the owning
 * tenant, the aggregate is the tenant domain. No producer records this
 * event yet; the outbox arrives in Stage 4 and attaches the producer to
 * RegisterDomain, in the same transaction as the row insert. The settled
 * trigger is registration: system-design 16.3 gates TLS issuance on
 * existence alone, and registration through the audited platform-admin
 * surface is the platform's act of verification. There is no verified_at
 * column and no challenge flow; if a later stage adds tenant self-service
 * domain registration, its verification flow gets a new event type.
 */
final readonly class DomainVerified
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public DomainVerifiedPayload $payload,
    ) {
        $this->aggregateType = 'tenant_domain';
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
