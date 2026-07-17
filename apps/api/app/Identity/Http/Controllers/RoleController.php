<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\CreateRole;
use App\Identity\Actions\DeleteRole;
use App\Identity\Actions\UpdateRole;
use App\Identity\Data\CreateRoleData;
use App\Identity\Data\RoleData;
use App\Identity\Data\UpdateRoleData;
use App\Identity\Exceptions\RoleNotFoundException;
use App\Identity\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class RoleController
{
    /**
     * @return PaginatedDataCollection<int, RoleData>
     */
    public function index(Request $request): PaginatedDataCollection
    {
        // Read only: no roles.manage capability required (stage-03 plan,
        // Roles and memberships endpoint table lists only
        // auth.unauthenticated and tenant_access_denied for this row).
        // roles_template_or_tenant_read RLS already scopes the result set
        // to global templates plus this tenant's own custom roles.
        $roles = QueryBuilder::for(Role::class)
            ->allowedFilters(AllowedFilter::partial('name'))
            ->allowedSorts('name')
            ->defaultSort('name')
            ->paginate()
            ->appends($request->query());

        return RoleData::collect($roles, PaginatedDataCollection::class);
    }

    public function store(CreateRoleData $data, CreateRole $createRole): JsonResponse
    {
        return response()->json($createRole($data), 201);
    }

    public function show(string $role): RoleData
    {
        return RoleData::fromModel($this->roleOrFail($role));
    }

    public function update(string $role, UpdateRoleData $data, UpdateRole $updateRole): RoleData
    {
        return $updateRole($this->roleOrFail($role), $data);
    }

    public function destroy(string $role, DeleteRole $deleteRole): Response
    {
        $deleteRole($this->roleOrFail($role));

        return response()->noContent();
    }

    /**
     * A well-formed but nonexistent or foreign-tenant role id renders the
     * same generic request.not_found problem a malformed id gets from
     * route-parameter matching, never a role-specific code:
     * roles_template_or_tenant_read RLS already makes another tenant's
     * custom role invisible to a plain find(), so this is a genuine 404,
     * not a denial, and existence never leaks (stage-03 plan, Slice 4
     * isolation denial probes).
     */
    private function roleOrFail(string $roleId): Role
    {
        return Role::query()->find($roleId) ?? throw RoleNotFoundException::forId($roleId);
    }
}
