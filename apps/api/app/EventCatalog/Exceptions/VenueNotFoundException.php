<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a venue id that resolves to no visible row: genuinely
 * nonexistent, or a foreign tenant's venue the tenant_isolation RLS
 * policy already hides from a plain find() (stage-05a plan, endpoint
 * table: "GET /v1/venues/{venue} ... request.not_found"), mirroring
 * App\Identity\Exceptions\RoleNotFoundException's own precedent. Maps to
 * the generic request.not_found code, not a venue-specific one, so
 * existence never leaks across tenants.
 */
final class VenueNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $venueId): self
    {
        return new self(sprintf('No venue has id "%s".', $venueId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
