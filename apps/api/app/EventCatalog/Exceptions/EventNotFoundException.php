<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for an event id that resolves to no visible row: genuinely
 * nonexistent, or a foreign tenant's event the tenant_isolation RLS
 * policy already hides from a plain find() (stage-05a plan, endpoint
 * table: "GET /v1/events/{event} ... request.not_found"), mirroring
 * App\EventCatalog\Exceptions\VenueNotFoundException's own precedent.
 * Maps to the generic request.not_found code, not an event-specific one,
 * so existence never leaks across tenants.
 */
final class EventNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $eventId): self
    {
        return new self(sprintf('No event has id "%s".', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
