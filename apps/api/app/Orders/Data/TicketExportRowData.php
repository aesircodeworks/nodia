<?php

namespace App\Orders\Data;

use App\Orders\Models\Ticket;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * One ticket's export facts (stage-11 plan, task 15/T12: the `tickets`
 * export source). Plain internal class, never serialized to the wire,
 * mirroring OrderExportRowData's own posture. listPrice is nullable
 * because it is resolved through App\Orders\Actions\GetTicketSaleFacts,
 * which already treats a ticket whose order carries no matching
 * order_item as a miss (the same defensive posture
 * App\Reporting\Jobs\ProjectDailySales takes on the same lookup); an
 * export row still exists for that ticket; it simply carries no price.
 */
final class TicketExportRowData
{
    public function __construct(
        public readonly string $id,
        public readonly string $eventId,
        public readonly string $ticketTypeId,
        public readonly string $orderId,
        public readonly string $status,
        public readonly ?string $attendeeName,
        public readonly ?Money $listPrice,
        public readonly string $issuedAt,
    ) {}

    public static function fromModel(Ticket $ticket, ?Money $listPrice): self
    {
        return new self(
            $ticket->id,
            $ticket->event_id,
            $ticket->ticket_type_id,
            $ticket->order_id,
            $ticket->status->value,
            $ticket->attendee_name,
            $listPrice,
            CarbonImmutable::instance($ticket->issued_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
