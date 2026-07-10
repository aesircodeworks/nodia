<?php

namespace App\Identity\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
class UpdateRoleData extends Data
{
    /**
     * @param  list<string>|Optional  $capabilities
     */
    public function __construct(
        public string|Optional $name,
        public array|Optional $capabilities,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'filled', 'max:255'],
            'capabilities' => ['sometimes', 'array', 'list'],
            'capabilities.*' => ['string', 'filled'],
        ];
    }
}
