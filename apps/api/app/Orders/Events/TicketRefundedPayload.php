<?php

namespace App\Orders\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for TicketRefunded. Defined in Stage 7 so the
 * section 9.3 registry's Orders event classes are complete; Stage 8b's
 * refund execution ships the first producer (stage-07 plan, Domain
 * events "Produced").
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class TicketRefundedPayload extends Data
{
    public function __construct(
        public string $ticketId,
        public string $orderId,
        public string $ticketTypeId,
        public string $eventId,
        public ?string $eventSeatId,
        public string $refundedAt,
        public ?string $refundId = null,
    ) {}
}
