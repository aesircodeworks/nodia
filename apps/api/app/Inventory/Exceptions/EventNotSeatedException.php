<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\GetStorefrontEventSeats when a
 * published event resolves but carries no materialized `event_seats`
 * rows (stage-06 plan, Endpoints "GET /v1/storefront/events/{event}/seats":
 * "409 event_not_seated for GA-only events"). A GA-only event never runs
 * App\Inventory\Actions\MaterializeEventSeats (only a seated publish
 * does), so an empty event_seats set is the reliable, Inventory-only
 * signal that this event has no seat map, without asking Catalog for
 * seat_map_id directly.
 */
final class EventNotSeatedException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $eventId): self
    {
        return new self(sprintf('Event "%s" is not a seated event.', $eventId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::EventNotSeated;
    }
}
