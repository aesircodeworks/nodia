<?php

namespace App\Tenancy\Events;

use App\Tenancy\Models\Tenant;

/**
 * Envelope per event-conventions: tenant creation is a platform-scope
 * fact, so the envelope tenant_id is the sentinel platform tenant, which
 * is also the app.tenant_id of the platform-posture transaction the
 * Stage 4 outbox insert will run in. No producer records this event yet;
 * the outbox arrives in Stage 4.
 */
final readonly class TenantCreated
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public TenantCreatedPayload $payload,
    ) {
        $this->aggregateType = 'tenant';
    }

    public static function fromTenant(Tenant $tenant): self
    {
        return new self(
            config()->string('tenancy.platform_tenant_id'),
            $tenant->id,
            new TenantCreatedPayload($tenant->id, $tenant->name, $tenant->default_locale),
        );
    }
}
