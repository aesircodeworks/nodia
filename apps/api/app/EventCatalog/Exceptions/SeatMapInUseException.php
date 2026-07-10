<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when a seat map delete is refused because at least one event
 * still references it through seat_map_id (stage-05b plan, Deletion
 * semantics: "the events FK restricts deletion of a template an event
 * has selected"). Detected by catching the events_seat_map_id_foreign
 * violation the database itself raises on the delete, not by a
 * read-then-write existence check (CLAUDE.md: invariant-guarding
 * transitions never read-then-write), mirroring
 * App\Identity\Exceptions\RoleInUseException's own precedent for
 * memberships_role_id_foreign. Stage 6 extends the same code to
 * templates referenced by materialized event_seats.
 */
final class SeatMapInUseException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $seatMapId): self
    {
        return new self(sprintf('Seat map "%s" is referenced by at least one event and cannot be deleted.', $seatMapId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogSeatMapInUse;
    }
}
