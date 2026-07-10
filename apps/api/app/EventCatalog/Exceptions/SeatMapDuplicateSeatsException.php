<?php

namespace App\EventCatalog\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use App\Support\Problems\HasValidationErrors;
use RuntimeException;

/**
 * Raised by UpsertSeatMap before any query runs (stage-05b plan, TDD
 * sequencing Slice 2: "rejects duplicate natural keys before touching the
 * database") when the payload's seats array carries two or more seats
 * sharing the same (section, row, number) natural key. The errors map
 * keys every offending seat's position in the payload's seats array
 * (stage-05b plan, Endpoints: "listing the offending positions in the
 * errors map"), e.g. seats.0, mirroring Laravel's own dot-notation for a
 * nested array item.
 */
final class SeatMapDuplicateSeatsException extends RuntimeException implements HasErrorCode, HasValidationErrors
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    private function __construct(string $message, private readonly array $errors)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<int>  $positions  every payload index sharing a
     *                                duplicated natural key, ascending
     */
    public static function forPositions(array $positions): self
    {
        $errors = [];

        foreach ($positions as $position) {
            $errors["seats.{$position}"] = [
                'This seat shares its section, row, and number with another seat in the same payload.',
            ];
        }

        return new self(
            'The seats array contains two or more seats sharing the same section, row, and number.',
            $errors,
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CatalogSeatMapDuplicateSeats;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
