<?php

namespace App\Tenancy\Events;

use App\Tenancy\Models\TenantDomain;

/**
 * Envelope per event-conventions: the envelope tenant_id is the owning
 * tenant, the aggregate is the tenant domain. No producer records this
 * event yet; the outbox arrives in Stage 4, and the precise trigger
 * (registration versus first verification) is an open question the
 * stage-02 plan settles before the producer attaches.
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
