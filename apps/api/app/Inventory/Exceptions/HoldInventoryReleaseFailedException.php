<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when the held-decrement guard in
 * App\Inventory\Actions\Concerns\ReleasesHoldInventory affects zero rows:
 * the ticket_type_inventory counter is missing or holds fewer units than
 * the hold item being released or expired. An active hold's units are
 * always counted into `held` at claim time, so this is a broken
 * invariant, not a user error; it surfaces as a generic server fault so
 * the surrounding release or expiry transaction rolls back rather than
 * recording HoldReleased or HoldExpired against inconsistent inventory.
 */
final class HoldInventoryReleaseFailedException extends RuntimeException implements HasErrorCode
{
    public static function forTicketType(string $ticketTypeId, int $quantity): self
    {
        return new self(sprintf(
            'Could not release %d held units for ticket type "%s": counter missing or below the held quantity.',
            $quantity,
            $ticketTypeId,
        ));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ServerInternalError;
    }
}
