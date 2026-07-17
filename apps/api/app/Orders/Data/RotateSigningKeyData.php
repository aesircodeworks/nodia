<?php

namespace App\Orders\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/events/{event}/signing-keys request body (stage-09 plan,
 * Endpoints). revoke_previous defaults to false; true marks the
 * outgoing key revoked instead of merely retired, the leak-response
 * path (Data model "event_signing_keys": "Rotation").
 */
#[MapName(SnakeCaseMapper::class)]
class RotateSigningKeyData extends Data
{
    public function __construct(
        public bool|Optional $revokePrevious,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'revoke_previous' => ['sometimes', 'boolean'],
        ];
    }

    public function revokePreviousOrDefault(): bool
    {
        return $this->revokePrevious instanceof Optional ? false : $this->revokePrevious;
    }
}
