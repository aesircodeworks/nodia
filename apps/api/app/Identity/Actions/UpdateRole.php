<?php

namespace App\Identity\Actions;

use App\Identity\Data\RoleData;
use App\Identity\Data\UpdateRoleData;
use App\Identity\Exceptions\RoleNameTakenException;
use App\Identity\Exceptions\RoleNotEditableException;
use App\Identity\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Spatie\LaravelData\Optional;

final class UpdateRole
{
    public function __invoke(Role $role, UpdateRoleData $data): RoleData
    {
        // Templates are never editable by a tenant (stage-03 plan Data
        // model): checked here, not left to the roles_tenant_update RLS
        // policy alone, so a caller sees role_not_editable instead of a
        // silent zero-row update.
        if ($role->isTemplate()) {
            throw RoleNotEditableException::forId($role->id);
        }

        $attributes = [];

        if (! $data->name instanceof Optional) {
            $attributes['name'] = $data->name;
        }

        if (! $data->capabilities instanceof Optional) {
            $attributes['capabilities'] = $data->capabilities;
        }

        try {
            $role->update($attributes);
        } catch (UniqueConstraintViolationException $e) {
            // fill() (inside update()) already set $role->name to the
            // colliding value in memory before the query that rejected it.
            if (str_contains($e->getMessage(), 'roles_tenant_id_name_unique')) {
                throw RoleNameTakenException::for($role->name);
            }

            throw $e;
        }

        return RoleData::fromModel($role);
    }
}
