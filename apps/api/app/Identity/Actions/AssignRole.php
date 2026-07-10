<?php

namespace App\Identity\Actions;

use App\Identity\Data\ChangeMembershipRoleData;
use App\Identity\Data\MembershipData;
use App\Identity\Events\UserRoleChanged;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Identity\Support\GuardsLastOwner;
use App\Support\Outbox\OutboxRecorder;

/**
 * PATCH /v1/memberships/{membership} (stage-03 plan, task breakdown item
 * 9). Runs inside the enclosing tenant request transaction the same way
 * App\Identity\Actions\InviteUser does (see its own docblock): the guard
 * query and the role_id update below are already atomic with each other.
 * UserRoleChanged is recorded into the outbox in that same transaction
 * (stage-04 plan, Slice 6).
 */
final class AssignRole
{
    use GuardsLastOwner;

    public function __construct(
        private readonly OutboxRecorder $outbox,
    ) {}

    public function __invoke(
        Membership $membership,
        ChangeMembershipRoleData $data,
        string $changedByUserId,
    ): MembershipData {
        $role = Role::visibleOrFail($data->roleId);

        $previousRoleId = $membership->role_id;

        if ($previousRoleId !== $role->id) {
            $this->assertNotLastOwner($membership);
        }

        $membership->update(['role_id' => $role->id]);

        $this->outbox->record(UserRoleChanged::fromMembership(
            $membership,
            $previousRoleId,
            $role->id,
            $changedByUserId,
        ));

        return MembershipData::fromModel($membership->refresh()->load(['user', 'role']));
    }
}
