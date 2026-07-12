<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/storefront/events/{event}/queue-entries request body
 * (stage-10 plan, Endpoints). challenge_response is optional at the
 * validation layer: whether it is actually required depends on the
 * resolved event's on_sale_policy.challenge_required, a policy the
 * request has no way to see ahead of time, so that check runs inside
 * App\Inventory\Actions\JoinQueue itself (challenge_required), never
 * here.
 */
#[MapName(SnakeCaseMapper::class)]
class JoinQueueData extends Data
{
    public function __construct(
        public string|Optional $challengeResponse,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'challenge_response' => ['sometimes', 'string'],
        ];
    }
}
