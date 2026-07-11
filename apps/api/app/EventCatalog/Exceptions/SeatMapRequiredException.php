<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when a publish is refused because the event carries at least
 * one requires_seat ticket type but no seat_map_id (stage-06 plan, Slice
 * 5: "Publish validation refuses an event carrying a requires_seat
 * ticket type but no seat_map_id", the Stage 5b deferral).
 * App\EventCatalog\Actions\PublishEvent checks this before its
 * conditional UPDATE: it is a shape validation on the event's own data,
 * not a concurrency guard, so a read-then-throw is the correct form here
 * (CLAUDE.md's read-then-write rule targets invariant-guarding state
 * transitions, not input validation).
 */
final class SeatMapRequiredException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $eventId): self
    {
        return new self(sprintf('Event "%s" has a requires_seat ticket type but no seat_map_id.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogSeatMapRequired;
    }
}
