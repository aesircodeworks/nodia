<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when a conditional UPDATE guarding the `sold + held <= quantity`
 * invariant (system-design 6, stage-06 plan Data model
 * "ticket_type_inventory") affects zero rows: App\Inventory\Actions\
 * AdjustInventoryQuantity's decrease guard today, and from a later task
 * the held-increment guard CreateHold issues. Zero affected rows means
 * insufficient inventory, never a read-then-write existence check
 * (master plan test-first rule 2; CLAUDE.md).
 */
final class InsufficientInventoryException extends RuntimeException implements HasErrorCode
{
    public static function forTicketType(string $ticketTypeId): self
    {
        return new self(sprintf(
            'Ticket type "%s" does not have sufficient inventory for this change.',
            $ticketTypeId,
        ));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InsufficientInventory;
    }
}
