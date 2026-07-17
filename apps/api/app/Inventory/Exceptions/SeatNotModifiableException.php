<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use App\Support\Problems\HasValidationErrors;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\UpdateEventSeats when any operation in
 * the batch fails its own conditional-UPDATE guard (stage-06 plan,
 * Endpoints "PATCH /v1/events/{event}/seats": "any guard failure rolls
 * back the whole batch with 409 seat_not_modifiable listing the
 * offending event_seat_ids"). All-or-nothing: every operation in the
 * request still runs so every offending id can be reported in one
 * response, and the whole transaction rolls back regardless of how many
 * operations succeeded.
 */
final class SeatNotModifiableException extends RuntimeException implements HasErrorCode, HasValidationErrors
{
    /**
     * @param  list<string>  $eventSeatIds
     */
    private function __construct(string $message, private readonly array $eventSeatIds)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $eventSeatIds
     */
    public static function forSeats(array $eventSeatIds): self
    {
        return new self(
            sprintf('The following seats cannot be modified: %s.', implode(', ', $eventSeatIds)),
            $eventSeatIds,
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::SeatNotModifiable;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return [
            'event_seat_ids' => $this->eventSeatIds,
        ];
    }
}
