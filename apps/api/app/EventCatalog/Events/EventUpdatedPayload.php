<?php

namespace App\EventCatalog\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for EventUpdated (stage-05a plan, Domain
 * events: "event_id"). Hidden from TypeScript generation: event payloads
 * are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class EventUpdatedPayload extends Data
{
    public function __construct(
        public string $eventId,
    ) {}
}
