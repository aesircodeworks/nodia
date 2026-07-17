<?php

namespace App\Identity\Support;

use App\Identity\Exceptions\LastOwnerRemovalException;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Shared by App\Identity\Actions\AssignRole (demotion) and RemoveMembership
 * (removal): both endpoints share the same last_owner_removal invariant
 * (stage-03 plan, Roles and memberships endpoint table), so the guard is
 * pulled out once rather than duplicated. "Owner" means a membership whose
 * role is the global Owner template (tenant_id null, name "Owner"), the
 * one App\Identity\Actions\SeedTemplateRoles seeds; a tenant's own custom
 * role happening to be named "Owner" is a different row and never
 * guarded, matching the plan's own vocabulary ("the only Owner") for the
 * seeded template, not an arbitrary tenant-chosen name.
 *
 * No dedicated concurrency test guards this: the stage-03 plan's TDD
 * sequencing section lists a "Concurrency (first for the guard)" step for
 * Slices 2, 5, 6, and 8 only, not Slice 4's last-owner invariant, the same
 * reading task-08's role_in_use guard already relied on (its own database
 * constraint needed no race test either). DB::transaction plus
 * lockForUpdate() on the remaining-Owner count still serializes concurrent
 * attempts against the same tenant's Owner memberships as a defensive
 * measure, even though nothing in this task's own test suite exercises
 * the race.
 */
trait GuardsLastOwner
{
    private function assertNotLastOwner(Membership $membership): void
    {
        DB::transaction(function () use ($membership): void {
            if (! self::isOwnerRole($membership->role_id)) {
                return;
            }

            $remains = Membership::query()
                ->where('tenant_id', $membership->tenant_id)
                ->where('role_id', $membership->role_id)
                ->whereKeyNot($membership->id)
                ->lockForUpdate()
                ->exists();

            if (! $remains) {
                throw LastOwnerRemovalException::forMembership($membership->id);
            }
        });
    }

    private static function isOwnerRole(string $roleId): bool
    {
        return Role::query()->whereKey($roleId)->whereNull('tenant_id')->where('name', 'Owner')->exists();
    }
}
