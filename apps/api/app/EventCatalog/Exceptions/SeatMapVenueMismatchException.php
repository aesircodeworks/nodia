<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\EventCatalog\Actions\UpdateEvent when an event's effective
 * seat_map_id (given in the request, or already stored, per the "given
 * together" bundle it belongs to) resolves to a seat map that does not
 * belong to the event's effective venue_id (stage-05b plan, Endpoints:
 * "the seat map must exist, be visible under RLS, and belong to the
 * event's venue, else 422 catalog.seat_map_venue_mismatch"). A seat map id
 * that resolves to no visible row at all (genuinely nonexistent, or a
 * foreign tenant's row the tenant_isolation RLS policy already hides from
 * a plain find()) is deliberately folded into this same code rather than
 * a 404: the plan's own wording combines "must exist, be visible under
 * RLS, and belong to the venue" into one 422 outcome, so a foreign
 * tenant's seat map id behaves exactly like a mismatched one, never
 * leaking existence across tenants.
 */
final class SeatMapVenueMismatchException extends RuntimeException implements HasErrorCode
{
    public static function forSeatMap(string $seatMapId, ?string $venueId): self
    {
        return new self(sprintf(
            'Seat map "%s" does not exist or does not belong to venue "%s".',
            $seatMapId,
            $venueId ?? 'null',
        ));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogSeatMapVenueMismatch;
    }
}
