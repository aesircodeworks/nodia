<?php

namespace App\Identity\Actions;

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;

/**
 * Resolves the membership (with its role loaded) a user acts under for a
 * given asserted tenant (stage-03 plan, Slice 4: "Gate resolution of the
 * acting membership from user plus asserted tenant"). CapabilityGate reads
 * the resolved membership's role capabilities from this; ResolveTenantAccess
 * (Slice 3) is a thin adapter over the same lookup, mapping the result to
 * its own allow/deny outcome rather than duplicating the query.
 *
 * Deliberately does none of its own SET LOCAL work, the same precedent
 * ResolveTenantAccess's own docblock records: the caller must already be
 * running inside a transaction with app.tenant_id set to the target tenant
 * and app.user_id set to the caller, so this Action's queries are answered
 * entirely by the memberships RLS policies. A tenant-scope membership in
 * the target tenant is tried first (satisfied by memberships_tenant_
 * isolation under nodia_app, or by nodia_platform's unconditional policy
 * once the caller has been elevated); a platform-scope membership is tried
 * only when no tenant-scope row exists, since a platform-scope row is
 * always pinned to the sentinel platform tenant (Membership::
 * assertScopeInvariant) and can only ever be surfaced through
 * memberships_self_read, which requires app.user_id.
 */
final class ResolveActingMembership
{
    public function forUser(string $userId, string $tenantId): ?Membership
    {
        $tenantMembership = Membership::query()
            ->with('role')
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('scope', MembershipScope::Tenant)
            ->first();

        if ($tenantMembership !== null) {
            return $tenantMembership;
        }

        return Membership::query()
            ->with('role')
            ->where('user_id', $userId)
            ->where('scope', MembershipScope::Platform)
            ->first();
    }
}
