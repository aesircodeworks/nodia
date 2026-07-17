<?php

namespace App\Identity\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class InviteUserData extends Data
{
    public function __construct(
        public string $email,
        public string $name,
        public string $roleId,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'filled', 'max:255'],
            'role_id' => ['required', 'string', 'uuid'],
        ];
    }
}
