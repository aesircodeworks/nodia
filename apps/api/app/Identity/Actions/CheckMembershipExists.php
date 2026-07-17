<?php

namespace App\Identity\Actions;

use App\Identity\Models\Membership;

/**
 * A minimal cross-context read for CheckIn's AssignCheckInUser (stage-09
 * plan, task breakdown item 12: "the target user_id has a membership in
 * the acting tenant"). Contexts never reach into another context's Models
 * directly (tests/Architecture/ContextBoundariesTest), so this one-line
 * existence check is exposed as an Action instead of CheckIn importing
 * App\Identity\Models\Membership directly, mirroring
 * App\Tenancy\Actions\ResolveTenantDefaultLocale's own precedent for a
 * minimal cross-context read.
 */
final class CheckMembershipExists
{
    public function __invoke(string $tenantId, string $userId): bool
    {
        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->exists();
    }
}
