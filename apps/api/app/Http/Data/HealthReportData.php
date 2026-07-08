<?php

namespace App\Http\Data;

use App\Enums\HealthStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class HealthReportData extends Data
{
    public function __construct(
        public HealthStatus $status,
        public HealthChecksData $checks,
        public string $checkedAt,
    ) {}

    public static function make(HealthChecksData $checks, DateTimeInterface $checkedAt): self
    {
        return new self(
            HealthStatus::Ok,
            $checks,
            CarbonImmutable::instance($checkedAt)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
