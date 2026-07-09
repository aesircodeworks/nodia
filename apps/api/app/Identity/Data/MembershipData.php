<?php

namespace App\Identity\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Wire shape only for now: memberships and roles ship in a later Stage 3
 * task, so nothing constructs this yet. CurrentUserData needs a concrete
 * element type for its always-present, currently-always-empty memberships
 * list.
 */
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
}
