<?php

namespace App\EventCatalog\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for EventCanceled (stage-05a plan, Domain
 * events: "event_id, canceled_at, prior_status"). prior_status lets
 * consumers ignore a canceled draft (never announced) differently from a
 * canceled published event, without a second event type (plan Risks:
 * "Cancel semantics"). Hidden from TypeScript generation: event payloads
 * are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class EventCanceledPayload extends Data
{
    public function __construct(
        public string $eventId,
        public string $canceledAt,
        public string $priorStatus,
    ) {}
}
