<?php

namespace App\Identity\Actions;

use App\Support\Tenancy\TenantContext;

/**
 * The read-only seam other contexts call to obtain the acting user's
 * capabilities for the current tenant, without importing
 * App\Identity\Models\Membership directly (boundary rule, system-design
 * 3.1). Orders' signing-key endpoints (stage-09 plan, Authorization
 * semantics paragraph) are the first cross-context consumer: they need
 * the capabilities list to build App\CheckIn\Data\
 * CheckEventAssignmentData without ever touching an Identity model.
 * Returns an empty list, never an exception, when no tenant context or
 * no membership resolves, mirroring ResolveActingMembership's own
 * null-on-miss posture; the caller decides what an empty list means.
 */
final class ResolveActingCapabilities
{
    public function __construct(
        private readonly ResolveActingMembership $membership,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<string>
     */
    public function __invoke(string $userId): array
    {
        if (! $this->context->hasTenant()) {
            return [];
        }

        $membership = $this->membership->forUser($userId, $this->context->tenantId());

        return $membership?->role->capabilities ?? [];
    }
}
