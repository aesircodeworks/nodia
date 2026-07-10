<?php

namespace App\Tenancy\Events;

use App\Support\Outbox\DomainEvent;
use App\Tenancy\Models\Tenant;

/**
 * Envelope per event-conventions: tenant creation is a platform-scope
 * fact, so the envelope tenant_id is the sentinel platform tenant, which
 * is also the app.tenant_id of the platform-posture transaction the
 * outbox insert runs in (CreateTenant under PlatformRequestTransaction).
 */
final readonly class TenantCreated implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public TenantCreatedPayload $payload,
    ) {
        $this->aggregateType = 'tenant';
    }

    public function type(): string
    {
        return 'TenantCreated';
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

    public function payload(): TenantCreatedPayload
    {
        return $this->payload;
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
