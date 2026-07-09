<?php

namespace App\Identity\Actions;

use App\Identity\Enums\MembershipAccessOutcome;
use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;

/**
 * Answers "may this user act as this tenant" (stage-03 plan, Slice 3:
 * activating membership validation in tenant resolution). Deliberately
 * does none of its own SET LOCAL work: the caller (Tenancy's
 * ResolveTenantFromHeader) must already be running inside a transaction
 * with app.tenant_id set to the target tenant and app.user_id set to the
 * caller, the same SET LOCAL wrapper every other tenant-scoped query runs
 * under, so this Action's single query is answered entirely by the
 * memberships RLS policies: memberships_tenant_isolation (visible rows
 * limited to tenant_id = app.tenant_id) ORed with memberships_self_read
 * (visible rows limited to user_id = app.user_id, independent of
 * tenant_id). A platform-scope row is always pinned to the sentinel
 * platform tenant (Membership::assertScopeInvariant), so it can only ever
 * be surfaced here through memberships_self_read, never tenant_isolation,
 * which is exactly why omitting app.user_id denies a platform-scope
 * caller by default rather than merely narrowing the result.
 */
final class ResolveTenantAccess
{
    public function forUser(string $userId, string $tenantId): MembershipAccessOutcome
    {
        $scopes = Membership::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($tenantId): void {
                $query->where(fn ($tenantScope) => $tenantScope
                    ->where('tenant_id', $tenantId)
                    ->where('scope', MembershipScope::Tenant))
                    ->orWhere('scope', MembershipScope::Platform);
            })
            ->pluck('scope');

        if ($scopes->contains(MembershipScope::Tenant)) {
            return MembershipAccessOutcome::TenantMember;
        }

        if ($scopes->contains(MembershipScope::Platform)) {
            return MembershipAccessOutcome::PlatformMember;
        }

        return MembershipAccessOutcome::Denied;
    }
}
