<?php

namespace App\Identity\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class CreateRoleData extends Data
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $name,
        public array $capabilities,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'filled', 'max:255'],
            // present, not required: Laravel's required rule fails an
            // empty array, but a role with no capabilities yet (to be
            // granted later through PATCH) is a valid create payload.
            'capabilities' => ['present', 'array', 'list'],
            'capabilities.*' => ['string', 'filled'],
        ];
    }
}
