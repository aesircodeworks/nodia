<?php

namespace App\Identity\Data;

use App\Models\User;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class CurrentUserData extends Data
{
    /**
     * @param  list<MembershipData>  $memberships
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public bool $mfaEnabled,
        public array $memberships,
    ) {}

    /**
     * @param  list<MembershipData>  $memberships
     */
    public static function fromModel(User $user, array $memberships): self
    {
        return new self(
            $user->id,
            $user->name,
            $user->email,
            $user->mfa_enabled,
            $memberships,
        );
    }
}
