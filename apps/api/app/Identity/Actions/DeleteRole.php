<?php

namespace App\Identity\Actions;

use App\Identity\Exceptions\RoleInUseException;
use App\Identity\Exceptions\RoleNotEditableException;
use App\Identity\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteRole
{
    public function __invoke(Role $role): void
    {
        if ($role->isTemplate()) {
            throw RoleNotEditableException::forId($role->id);
        }

        // memberships.role_id carries a foreign key with no cascade
        // (database/migrations/2026_07_09_000013_create_memberships_table.php),
        // so Postgres itself refuses the delete while a membership still
        // references the role; the constraint is the invariant guard,
        // never a read-then-write existence check (CLAUDE.md), and is
        // enforced atomically regardless of concurrent membership
        // creation. No Laravel-native subclass of QueryException exists
        // for a foreign key violation the way UniqueConstraintViolationException
        // does for a unique violation, so the SQLSTATE is checked
        // directly, mirroring PostgresConnection::causedByUniqueConstraintViolation's
        // own '23505' check for '23503' (foreign_key_violation). Deletes
        // through the DB query builder rather than $role->delete():
        // Larastan can fully resolve Eloquent Model::delete()'s own
        // signature (no @throws QueryException) and reports this catch as
        // dead code for it, but cannot for the facade-dispatched call, so
        // the query builder form is both correct and the one Larastan
        // accepts without a suppression comment.
        try {
            DB::table('roles')->where('id', $role->id)->delete();
        } catch (QueryException $e) {
            if ($e->getCode() === '23503' && str_contains($e->getMessage(), 'memberships_role_id_foreign')) {
                throw RoleInUseException::forId($role->id);
            }

            throw $e;
        }
    }
}
