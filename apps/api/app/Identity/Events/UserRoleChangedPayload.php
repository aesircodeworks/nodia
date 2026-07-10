<?php

namespace App\Identity\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for UserRoleChanged (stage-04 plan, Domain events).
 * Identifiers only: email and name are deliberately absent so retained
 * outbox rows never outlive PII erasure (system-design 9.1, 14.3).
 * Hidden from TypeScript generation: event payloads are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class UserRoleChangedPayload extends Data
{
    public function __construct(
        public string $membershipId,
        public string $userId,
        public string $previousRoleId,
        public string $newRoleId,
        public string $changedByUserId,
    ) {}
}
