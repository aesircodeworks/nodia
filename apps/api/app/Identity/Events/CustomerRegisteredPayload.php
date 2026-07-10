<?php

namespace App\Identity\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for CustomerRegistered (stage-04 plan, Domain events).
 * Identifiers and facts only: email and name are deliberately absent so
 * retained outbox rows never outlive PII erasure (system-design 9.1, 14.3).
 * Hidden from TypeScript generation: event payloads are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class CustomerRegisteredPayload extends Data
{
    public function __construct(
        public string $customerId,
        public bool $isGuest,
    ) {}
}
