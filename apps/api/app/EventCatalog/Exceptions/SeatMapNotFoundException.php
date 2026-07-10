<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a seat map id that resolves to no visible row: genuinely
 * nonexistent, or a foreign tenant's seat map the tenant_isolation RLS
 * policy already hides from a plain find() (stage-05b plan, Endpoints:
 * "GET /v1/seat-maps/{seat_map} ... 404"), mirroring
 * App\EventCatalog\Exceptions\VenueNotFoundException's own precedent.
 * Maps to the generic request.not_found code, not a seat-map-specific
 * one, so existence never leaks across tenants.
 */
final class SeatMapNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $seatMapId): self
    {
        return new self(sprintf('No seat map has id "%s".', $seatMapId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
