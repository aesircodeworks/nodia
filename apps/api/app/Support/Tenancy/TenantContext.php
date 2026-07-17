<?php

namespace App\Support\Tenancy;

use App\Support\Database\Rls;
use LogicException;

/**
 * Request-scoped holder for the tenant a request is executing as. Octane
 * reuses workers across requests, so tenant state must never live in
 * static or singleton state (system-design 4.1); this class is bound
 * scoped() in the container so Octane resets it between requests, and
 * TenantTransaction clears it when the wrapping transaction ends either
 * way, so no posture outlives the transaction that established it.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private ?string $role = null;

    public function enter(string $tenantId, string $role): void
    {
        $this->tenantId = $tenantId;
        $this->role = $role;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->role = null;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function tenantId(): string
    {
        if ($this->tenantId === null) {
            throw new LogicException('No tenant context is active; tenantId() may only be called inside a tenant transaction.');
        }

        return $this->tenantId;
    }

    public function isPlatform(): bool
    {
        return $this->role === Rls::PLATFORM_ROLE;
    }
}
