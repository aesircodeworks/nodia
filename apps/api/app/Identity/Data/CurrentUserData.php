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

    public static function fromModel(User $user): self
    {
        return new self(
            $user->id,
            $user->name,
            $user->email,
            // users.mfa_enabled ships in a later Stage 3 task; no user can
            // have MFA confirmed before that column exists.
            false,
            // memberships and roles ship in a later Stage 3 task.
            [],
        );
    }
}
