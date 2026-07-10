<?php

namespace App\Identity\Actions;

use App\Identity\Data\ChangeMembershipRoleData;
use App\Identity\Data\MembershipData;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Identity\Support\GuardsLastOwner;

/**
 * PATCH /v1/memberships/{membership} (stage-03 plan, task breakdown item
 * 9). Runs inside the enclosing tenant request transaction the same way
 * App\Identity\Actions\InviteUser does (see its own docblock): the guard
 * query and the role_id update below are already atomic with each other,
 * the "single transaction with an obvious Stage 4 outbox attachment
 * point" the Domain events section asks for (UserRoleChanged).
 */
final class AssignRole
{
    use GuardsLastOwner;

    public function __invoke(Membership $membership, ChangeMembershipRoleData $data): MembershipData
    {
        $role = Role::visibleOrFail($data->roleId);

        if ($membership->role_id !== $role->id) {
            $this->assertNotLastOwner($membership);
        }

        // Stage 4 attachment point: Outbox::record(UserRoleChanged::class,
        // ...) belongs here, inside the same transaction as the update
        // below (stage-03 plan, Domain events).

        $membership->update(['role_id' => $role->id]);

        return MembershipData::fromModel($membership->refresh()->load(['user', 'role']));
    }
}
