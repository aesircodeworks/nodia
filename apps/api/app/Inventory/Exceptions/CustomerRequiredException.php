<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CreateHold when an item's ticket type
 * carries a max_per_customer limit but no customer is authenticated
 * (stage-10 plan, Endpoints "POST /v1/storefront/holds": "Limited ticket
 * type without customer_id", code customer_required). Checked before any
 * inventory or counter statement runs, alongside the existing sales-window
 * and ticket-type-membership checks in App\Inventory\Actions\CreateHold::
 * assertHoldable.
 */
final class CustomerRequiredException extends RuntimeException implements HasErrorCode
{
    public static function forTicketType(string $ticketTypeId): self
    {
        return new self(sprintf(
            'Ticket type "%s" has a per-customer purchase limit and requires an authenticated customer.',
            $ticketTypeId,
        ));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CustomerRequired;
    }
}
