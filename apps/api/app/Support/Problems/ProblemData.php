<?php

namespace App\Support\Problems;

use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapName(SnakeCaseMapper::class)]
class ProblemData extends Data
{
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
        public string $code,
        public string|Optional $correlationId,
    ) {}

    public static function fromErrorCode(ErrorCode $code, string $detail, ?string $correlationId = null): self
    {
        return new self(
            $code->type(),
            $code->title(),
            $code->status(),
            $detail,
            $code->value,
            $correlationId ?? Optional::create(),
        );
    }

    /**
     * @param  array<string, string|string[]>  $headers
     */
    public function toProblemResponse(array $headers = []): JsonResponse
    {
        return response()->json($this, $this->status, $headers + ['Content-Type' => 'application/problem+json']);
    }
}
