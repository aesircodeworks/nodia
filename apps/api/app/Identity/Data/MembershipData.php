<?php

namespace App\Identity\Data;

use App\Identity\Models\Membership;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class MembershipData extends Data
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $userName,
        public string $userEmail,
        public string $tenantId,
        public string $roleId,
        public string $roleName,
        public string $scope,
    ) {}

    /**
     * $membership must have its user and role relations already loaded
     * (stage-03 plan, task breakdown item 9): App\Identity\Actions\ListOwnMemberships
     * (task-05) builds MembershipData by hand instead, because that
     * Action's role lookup is a separate per-tenant transaction, not an
     * eager-loaded relation on the same connection.
     */
    public static function fromModel(Membership $membership): self
    {
        return new self(
            $membership->id,
            $membership->user_id,
            $membership->user->name,
            $membership->user->email,
            $membership->tenant_id,
            $membership->role_id,
            $membership->role->name,
            $membership->scope->value,
        );
    }
}
