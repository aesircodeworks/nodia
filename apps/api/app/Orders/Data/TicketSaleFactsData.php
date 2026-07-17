<?php

namespace App\Orders\Data;

use App\Support\Money\Money;

/**
 * The per-ticket sale facts App\Orders\Actions\GetTicketSaleFacts
 * resolves for cross-context consumers (stage-11 plan, Data model
 * "report_daily_sales" money semantics note): neither TicketIssued nor
 * TicketRefunded carries the ticket type's list price at issue, so a
 * consumer resolves it here instead of the outbox payload. event_id and
 * ticket_type_id ride along too, so a caller never has to trust a
 * payload's own copies of those fields for a fact this Action already
 * has to load the ticket row to answer (event-conventions: consumers
 * needing more load it through the owning context's Actions). Never
 * serialized to the wire, mirroring OrderRefundContextData's own
 * posture.
 */
final class TicketSaleFactsData
{
    public function __construct(
        public readonly string $ticketId,
        public readonly string $eventId,
        public readonly string $ticketTypeId,
        public readonly Money $listPrice,
    ) {}
}
