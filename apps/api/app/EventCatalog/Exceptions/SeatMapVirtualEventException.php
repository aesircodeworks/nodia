<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\EventCatalog\Actions\UpdateEvent when an event's effective
 * is_virtual is true while its effective seat_map_id is non-null
 * (stage-05b plan, Endpoints: "a virtual event rejects a non-null value
 * with 422 catalog.seat_map_virtual_event"), whether the request sets
 * seat_map_id directly on a virtual event, or flips is_virtual to true on
 * an event whose seat_map_id is already set without clearing the link in
 * the same request.
 */
final class SeatMapVirtualEventException extends RuntimeException implements HasErrorCode
{
    public static function forEvent(string $eventId): self
    {
        return new self(sprintf('Event "%s" is virtual and cannot have a seat_map_id.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogSeatMapVirtualEvent;
    }
}
