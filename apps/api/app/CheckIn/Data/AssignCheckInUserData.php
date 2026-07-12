<?php

namespace App\CheckIn\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/events/{event}/check-in-assignments request body (stage-09
 * plan, Endpoints "Check-in assignments").
 */
#[MapName(SnakeCaseMapper::class)]
class AssignCheckInUserData extends Data
{
    public function __construct(
        public string $userId,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'uuid'],
        ];
    }
}
