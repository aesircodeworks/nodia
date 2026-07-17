<?php

namespace App\Http\Data;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\ProblemData;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
class HealthDegradedProblemData extends ProblemData
{
    public function __construct(
        string $type,
        string $title,
        int $status,
        string $detail,
        string $code,
        string|Optional $correlationId,
        public HealthChecksData $checks,
        public string $checkedAt,
    ) {
        parent::__construct($type, $title, $status, $detail, $code, $correlationId);
    }

    public static function make(HealthChecksData $checks, string $checkedAt): self
    {
        $code = ErrorCode::HealthDegraded;

        return new self(
            $code->type(),
            $code->title(),
            $code->status(),
            'One or more backing services failed their health check.',
            $code->value,
            // The correlation ID travels only in the X-Correlation-Id header
            // on this response; the body shape is frozen contract.
            Optional::create(),
            $checks,
            $checkedAt,
        );
    }
}
