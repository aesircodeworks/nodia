<?php

namespace App\Orders\Events;

use App\Orders\Models\Ticket;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for TicketIssued (stage-07 plan, Domain
 * events). attendee_name deliberately stays out: outbox rows are
 * immutable and replayed, so PII there would defeat the
 * anonymize-in-place erasure of system-design 14.3; consumers load
 * ticket details through an Orders Action by ticket_id. order_id is in
 * the payload so Stage 8a's per-order consumers (confirmation email)
 * can group and key idempotence per order.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class TicketIssuedPayload extends Data
{
    public function __construct(
        public string $ticketId,
        public string $orderId,
        public string $ticketTypeId,
        public string $eventId,
        public ?string $eventSeatId,
        public string $issuedAt,
    ) {}

    public static function fromTicket(Ticket $ticket): self
    {
        return new self(
            $ticket->id,
            $ticket->order_id,
            $ticket->ticket_type_id,
            $ticket->event_id,
            $ticket->event_seat_id,
            CarbonImmutable::instance($ticket->issued_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
