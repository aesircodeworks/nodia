<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by UpsertSeatMap when a create collides with the
 * seat_maps_venue_id_name_unique index (stage-05b plan, Data model:
 * "Unique (venue_id, name)"). The (venue_id, name) unique index is the
 * invariant guard, never a read-then-write existence check (CLAUDE.md),
 * mirroring App\Identity\Exceptions\RoleNameTakenException's own
 * precedent: the database decides who wins a concurrent create with the
 * same name, and the loser's violation is translated here by constraint
 * name.
 */
final class SeatMapNameTakenException extends RuntimeException implements HasErrorCode
{
    public static function for(string $venueId, string $name): self
    {
        return new self(sprintf('A seat map named "%s" already exists for venue "%s".', $name, $venueId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogSeatMapNameTaken;
    }
}
