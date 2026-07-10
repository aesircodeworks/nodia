<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a ticket type id that resolves to no visible row: genuinely
 * nonexistent, or a foreign tenant's ticket type the tenant_isolation RLS
 * policy already hides from a plain find() (stage-05a plan, endpoint
 * table: "GET /v1/ticket-types/{ticket_type} ... request.not_found"),
 * mirroring App\EventCatalog\Exceptions\EventNotFoundException's own
 * precedent. Maps to the generic request.not_found code, not a
 * ticket-type-specific one, so existence never leaks across tenants.
 */
final class TicketTypeNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $ticketTypeId): self
    {
        return new self(sprintf('No ticket type has id "%s".', $ticketTypeId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
