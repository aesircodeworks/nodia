<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CreateHold when an item's
 * ticket_type_id does not belong to the requested event (stage-06 plan,
 * Endpoints failure table: "Ticket type not in event", code
 * ticket_type_not_in_event).
 */
final class TicketTypeNotInEventException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $ticketTypeId): self
    {
        return new self(sprintf('Ticket type "%s" does not belong to the given event.', $ticketTypeId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TicketTypeNotInEvent;
    }
}
