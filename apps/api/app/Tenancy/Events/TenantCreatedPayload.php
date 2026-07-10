<?php

namespace App\Tenancy\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for TenantCreated (stage-04 plan, Domain events).
 * Identifiers and facts only. Hidden from TypeScript generation: event
 * payloads are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class TenantCreatedPayload extends Data
{
    public function __construct(
        public string $tenantId,
        public string $name,
        public string $defaultLocale,
    ) {}
}
