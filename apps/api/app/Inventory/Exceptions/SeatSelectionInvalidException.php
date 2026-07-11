<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CreateHold's structural seat-selection
 * check (stage-06 plan, Endpoints: "Seated type without seats, seat
 * count not matching quantity, or seats on a GA type" -> 422
 * seat_selection_invalid). Purely arithmetic against the request itself
 * (item quantities, requires_seat flags, and the seat_ids count), never a
 * database read of seat status: a seat that exists but is unavailable is
 * App\Inventory\Exceptions\SeatUnavailableException's own case instead.
 */
final class SeatSelectionInvalidException extends RuntimeException implements HasErrorCode
{
    public static function create(): self
    {
        return new self('The seat_ids do not match the requested items.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::SeatSelectionInvalid;
    }
}
