<?php

namespace App\Orders\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for TicketCanceled. Defined in Stage 7 so the
 * section 9.3 registry's Orders event classes are complete; no producer
 * exists until event cancellation work lands (stage-07 plan, Domain
 * events "Produced").
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class TicketCanceledPayload extends Data
{
    public function __construct(
        public string $ticketId,
        public string $orderId,
        public string $ticketTypeId,
        public string $eventId,
        public ?string $eventSeatId,
        public string $canceledAt,
    ) {}
}
