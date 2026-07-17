<?php

namespace App\Identity\Actions;

use App\Identity\Data\CreateRoleData;
use App\Identity\Data\RoleData;
use App\Identity\Exceptions\RoleNameTakenException;
use App\Identity\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

final class CreateRole
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function __invoke(CreateRoleData $data): RoleData
    {
        // The (tenant_id, name) unique index is the invariant guard, never
        // a read-then-write existence check (CLAUDE.md): the database
        // decides who wins a concurrent create with the same name, and the
        // loser's violation is translated here by constraint name,
        // mirroring RegisterDomain's own precedent. Unknown capability
        // names are caught by Role::assertKnownCapabilities() via the
        // model's saving hook before any query runs.
        try {
            $role = Role::create([
                'tenant_id' => $this->tenantContext->tenantId(),
                'name' => $data->name,
                'capabilities' => $data->capabilities,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'roles_tenant_id_name_unique')) {
                throw RoleNameTakenException::for($data->name);
            }

            throw $e;
        }

        return RoleData::fromModel($role);
    }
}
