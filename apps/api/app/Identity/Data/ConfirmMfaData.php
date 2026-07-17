<?php

namespace App\Identity\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class ConfirmMfaData extends Data
{
    public function __construct(
        public string $code,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'code' => ['required', 'string'],
        ];
    }
}
