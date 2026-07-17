<?php

namespace App\Orders\Data;

use App\Orders\Models\Ticket;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One ticket in the buyer's GET
 * /v1/storefront/orders/{order}/tickets response (stage-07 plan,
 * Endpoints). qr_payload is computed on render by TicketQrCodec and
 * never stored (system-design 8.3 notes).
 */
#[MapName(SnakeCaseMapper::class)]
class TicketData extends Data
{
    public function __construct(
        public string $id,
        public string $ticketTypeId,
        public ?string $eventSeatId,
        public string $status,
        public ?string $attendeeName,
        public string $issuedAt,
        public string $qrPayload,
    ) {}

    public static function fromModel(Ticket $ticket, string $qrPayload): self
    {
        return new self(
            $ticket->id,
            $ticket->ticket_type_id,
            $ticket->event_seat_id,
            $ticket->status->value,
            $ticket->attendee_name,
            CarbonImmutable::instance($ticket->issued_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $qrPayload,
        );
    }
}
