<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by UpsertSeatMap::replace() when a seat insert collides with the
 * seats_seat_map_id_section_row_number_unique index despite the
 * lockForUpdate on the seat_maps row (stage-05b plan, Endpoints: "A
 * residual unique violation ... maps to a 409 catalog.seat_map_conflict
 * problem, never a 500"). Defense in depth, not a reachable outcome of
 * two well-behaved PUT requests: replace()'s own lock serializes every
 * writer that goes through it, and the natural-key diff (matched,
 * inserted, deleted sets) is computed from a read taken under that lock,
 * so the insert set is always disjoint from what remains. This guards
 * against anything that bypasses the lock (a bug, a future code path, a
 * manual database write) surfacing as an opaque 500 instead of a stable
 * problem code.
 */
final class SeatMapConflictException extends RuntimeException implements HasErrorCode
{
    public static function for(string $seatMapId): self
    {
        return new self(sprintf('A seat on seat map "%s" collided with an existing natural key.', $seatMapId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogSeatMapConflict;
    }
}
