<?php

namespace App\Identity\Actions;

use App\Identity\Enums\MembershipAccessOutcome;
use App\Identity\Enums\MembershipScope;

/**
 * Answers "may this user act as this tenant" (stage-03 plan, Slice 3:
 * activating membership validation in tenant resolution). Delegates the
 * actual lookup to ResolveActingMembership (Slice 4), the primitive
 * CapabilityGate also resolves the acting role from, rather than
 * duplicating the query; this class only maps the resolved membership's
 * scope to its own allow/deny outcome. The RLS-context prerequisite
 * (app.tenant_id and app.user_id already SET LOCAL by the caller) is
 * ResolveActingMembership's own docblock now, not repeated here.
 */
final class ResolveTenantAccess
{
    public function __construct(private readonly ResolveActingMembership $membership) {}

    public function forUser(string $userId, string $tenantId): MembershipAccessOutcome
    {
        $membership = $this->membership->forUser($userId, $tenantId);

        return match ($membership?->scope) {
            MembershipScope::Tenant => MembershipAccessOutcome::TenantMember,
            MembershipScope::Platform => MembershipAccessOutcome::PlatformMember,
            null => MembershipAccessOutcome::Denied,
        };
    }
}
