<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a ticket_type_id that resolves to no ticket_type_inventory
 * row: genuinely nonexistent, or a foreign tenant's row the
 * tenant_isolation RLS policy already hides from a plain query (stage-06
 * plan, Endpoints "GET /v1/ticket-types/{ticket_type}/inventory").
 * A quantity-seeded inventory row is created for every ticket type at
 * creation time (App\Inventory\Actions\InitializeTicketTypeInventory),
 * so a missing row means the ticket type itself does not exist for this
 * tenant. Maps to the generic request.not_found code, not a
 * ticket-type-specific one, mirroring
 * App\EventCatalog\Exceptions\TicketTypeNotFoundException's own
 * precedent so existence never leaks across tenants.
 */
final class TicketTypeInventoryNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forTicketType(string $ticketTypeId): self
    {
        return new self(sprintf('No ticket_type_inventory row for ticket type "%s".', $ticketTypeId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
