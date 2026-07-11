<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use App\Support\Problems\HasValidationErrors;
use RuntimeException;

/**
 * Raised by App\Inventory\Actions\CreateHold when the per-item seat
 * claim's conditional UPDATE (`available -> held`, guarded by event_id,
 * status, and ticket_type_id) affects fewer rows than the item's
 * quantity (stage-06 plan, Endpoints: "Seat not available (held, sold,
 * blocked, wrong zone, wrong event)" -> 409 seat_unavailable). Implements
 * HasValidationErrors so the response identifies the offending seat_ids
 * in an extension member (stage-06 plan, Endpoints: "seat_unavailable
 * responses identify the failing ... seat_ids in an extension member").
 */
final class SeatUnavailableException extends RuntimeException implements HasErrorCode, HasValidationErrors
{
    /**
     * @param  list<string>  $seatIds
     */
    private function __construct(string $message, private readonly array $seatIds)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $seatIds
     */
    public static function forSeats(array $seatIds): self
    {
        return new self(
            sprintf('The following seats are not available: %s.', implode(', ', $seatIds)),
            $seatIds,
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::SeatUnavailable;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return [
            'seat_ids' => $this->seatIds,
        ];
    }
}
