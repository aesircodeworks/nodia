<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CreateHold when an item's ticket type
 * is requested outside its sales_start/sales_end window (stage-06 plan,
 * Endpoints failure table: "Outside the ticket type's sales window",
 * code sales_window_closed).
 */
final class SalesWindowClosedException extends RuntimeException implements HasErrorCode
{
    public static function forTicketType(string $ticketTypeId): self
    {
        return new self(sprintf('Ticket type "%s" is outside its sales window.', $ticketTypeId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::SalesWindowClosed;
    }
}
