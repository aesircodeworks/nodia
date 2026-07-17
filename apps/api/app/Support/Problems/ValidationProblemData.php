<?php

namespace App\Support\Problems;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
class ValidationProblemData extends ProblemData
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $type,
        string $title,
        int $status,
        string $detail,
        string $code,
        string|Optional $correlationId,
        public array $errors,
    ) {
        parent::__construct($type, $title, $status, $detail, $code, $correlationId);
    }

    /**
     * $code defaults to the generic request.validation_failed (framework
     * ValidationException's own path through ProblemRenderer); a
     * HasValidationErrors domain exception passes its own stable code
     * instead (stage-05b plan: catalog.seat_map_duplicate_seats still
     * carries the errors map extension member).
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function fromErrors(array $errors, string $detail, ?string $correlationId = null, ErrorCode $code = ErrorCode::RequestValidationFailed): self
    {
        return new self(
            $code->type(),
            $code->title(),
            $code->status(),
            $detail,
            $code->value,
            $correlationId ?? Optional::create(),
            $errors,
        );
    }
}
