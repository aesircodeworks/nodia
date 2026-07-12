<?php

namespace App\CheckIn\Data;

use App\CheckIn\Enums\ScanOutcome;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One entry of POST /v1/check-in-batches' response (stage-09 plan,
 * Endpoints "POST /v1/check-in-batches"). code and check_in_id are
 * mutually exclusive in practice: accepted and duplicate outcomes carry
 * a check_in_id and no code, rejected outcomes carry a code (the same
 * stable codes as the single-scan endpoint) and no check_in_id.
 * first_scanned_at and first_device_id are populated only for
 * duplicate outcomes.
 */
#[MapName(SnakeCaseMapper::class)]
class ScanOutcomeData extends Data
{
    public function __construct(
        public string $clientScanId,
        public ScanOutcome $outcome,
        public ?string $checkInId = null,
        public ?string $code = null,
        public ?string $firstScannedAt = null,
        public ?string $firstDeviceId = null,
    ) {}
}
