<?php

namespace App\Orders\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Input to the VerifyCheckInQr Action (stage-09 plan, Task 8: "Orders
 * read Actions for CheckIn"). Built by callers directly from the scan
 * payload, never bound from an HTTP request body, so it carries no
 * rules().
 */
#[MapName(SnakeCaseMapper::class)]
class VerifyCheckInQrData extends Data
{
    public function __construct(
        public string $qrPayload,
    ) {}
}
