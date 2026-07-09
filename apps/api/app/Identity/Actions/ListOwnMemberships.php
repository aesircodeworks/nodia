<?php

namespace App\Identity\Actions;

use App\Identity\Data\MembershipData;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;

/**
 * GET /v1/me's membership list (stage-03 plan, Slice 3: "GET /v1/me now
 * returns the caller's memberships through memberships_self_read with
 * app.user_id set by the auth layer"). Two kinds of transaction, run
 * sequentially rather than nested (TenantTransaction::asAuthenticatedStaff
 * docblock explains why nesting is unsafe): first the self-only posture
 * reads every one of the caller's own membership rows across every tenant
 * through memberships_self_read alone; then, because roles are RLS-scoped
 * per tenant and a template's or a foreign tenant's custom role is
 * otherwise invisible without asserting that specific tenant, one ordinary
 * asTenant() lookup per distinct tenant resolves that membership's role
 * name. memberships.unique(user_id, tenant_id) guarantees at most one
 * membership row per distinct tenant_id, so this is exactly one role
 * lookup per row, never more.
 */
final class ListOwnMemberships
{
    public function __construct(private readonly TenantTransaction $transaction) {}

    /**
     * @return list<MembershipData>
     */
    public function forUser(User $user): array
    {
        $memberships = $this->transaction->asAuthenticatedStaff(
            $user->id,
            fn () => Membership::query()->where('user_id', $user->id)->get(['id', 'tenant_id', 'role_id', 'scope']),
        );

        return $memberships
            ->map(function (Membership $membership) use ($user): MembershipData {
                /** @var Role $role */
                $role = $this->transaction->asTenant(
                    $membership->tenant_id,
                    fn () => Role::query()->findOrFail($membership->role_id),
                );

                return new MembershipData(
                    $membership->id,
                    $user->id,
                    $user->name,
                    $user->email,
                    $membership->tenant_id,
                    $membership->role_id,
                    $role->name,
                    $membership->scope->value,
                );
            })
            ->all();
    }
}
