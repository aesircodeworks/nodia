<?php

namespace App\Identity\Actions;

use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Identity\Support\MfaEnforcementPolicy;
use App\Support\Tenancy\TenantTransaction;

/**
 * Answers "does this user hold at least one membership that mandates
 * confirmed MFA", across every tenant, for POST /v1/auth/mfa/disable
 * (stage-03 plan, MFA endpoint table: mfa_enforced_for_role). Unlike
 * App\Http\Middleware\EnforceMfaCompliance, which only ever evaluates the
 * single acting membership under one asserted tenant, disable has no
 * X-Tenant-Id (MFA is a bearer-only namespace): every membership the
 * caller holds anywhere must be checked. Mirrors ListOwnMemberships'
 * sequential-transaction pattern (the self-only posture to list
 * membership rows, then one per-tenant lookup for each role) rather than
 * nesting, for the same reason its own docblock records.
 */
final class HasEnforcingMembership
{
    public function __construct(private readonly TenantTransaction $transaction) {}

    public function forUser(string $userId): bool
    {
        $memberships = $this->transaction->asAuthenticatedStaff(
            $userId,
            fn () => Membership::query()->where('user_id', $userId)->get(['tenant_id', 'role_id', 'scope']),
        );

        foreach ($memberships as $membership) {
            /** @var Role $role */
            $role = $this->transaction->asTenant(
                $membership->tenant_id,
                fn () => Role::query()->findOrFail($membership->role_id),
            );

            if (MfaEnforcementPolicy::requires($membership->scope, $role->capabilities)) {
                return true;
            }
        }

        return false;
    }
}
