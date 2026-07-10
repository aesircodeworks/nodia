<?php

namespace App\Identity\Http\Controllers;

use App\Identity\Actions\AssignRole;
use App\Identity\Actions\InviteUser;
use App\Identity\Actions\RemoveMembership;
use App\Identity\Data\ChangeMembershipRoleData;
use App\Identity\Data\InviteUserData;
use App\Identity\Data\MembershipData;
use App\Identity\Exceptions\MembershipNotFoundException;
use App\Identity\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class MembershipController
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @return PaginatedDataCollection<int, MembershipData>
     */
    public function index(Request $request): PaginatedDataCollection
    {
        // Explicitly scoped to the asserted tenant rather than relying on
        // RLS alone: memberships_self_read (task-04) permissively widens
        // SELECT visibility to the caller's own membership rows in every
        // tenant, which would otherwise leak into "list memberships in
        // this tenant" whenever the caller happens to also be a member
        // elsewhere. ANDed with RLS's own predicate, this filter narrows
        // back down to exactly the asserted tenant regardless.
        $memberships = QueryBuilder::for(Membership::class)
            ->allowedFilters(AllowedFilter::exact('user_id'), AllowedFilter::exact('role_id'))
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->where('tenant_id', $this->tenantContext->tenantId())
            ->with(['user', 'role'])
            ->paginate()
            ->appends($request->query());

        return MembershipData::collect($memberships, PaginatedDataCollection::class);
    }

    public function store(Request $request, InviteUserData $data, InviteUser $inviteUser): JsonResponse
    {
        $staff = $request->user('staff');

        if (! $staff instanceof User) {
            throw new LogicException('MembershipController::store requires an authenticated staff user; ensure auth:staff runs first.');
        }

        return response()->json($inviteUser($data, $staff->id), 201);
    }

    public function update(string $membership, ChangeMembershipRoleData $data, AssignRole $assignRole): MembershipData
    {
        return $assignRole($this->membershipOrFail($membership), $data);
    }

    public function destroy(string $membership, RemoveMembership $removeMembership): Response
    {
        $removeMembership($this->membershipOrFail($membership));

        return response()->noContent();
    }

    /**
     * Scoped to the asserted tenant for the same reason index() is: a
     * membership id that is the caller's own row in a different tenant is
     * otherwise visible via memberships_self_read's SELECT-only OR, which
     * would make find() succeed while the UPDATE/DELETE that follows
     * silently affects zero rows under RLS (self-read never widens
     * INSERT, UPDATE, or DELETE, task-04 journal) instead of raising
     * request.not_found.
     */
    private function membershipOrFail(string $membershipId): Membership
    {
        return Membership::query()
            ->where('tenant_id', $this->tenantContext->tenantId())
            ->with(['user', 'role'])
            ->find($membershipId) ?? throw MembershipNotFoundException::forId($membershipId);
    }
}
