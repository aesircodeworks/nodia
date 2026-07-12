<?php

namespace App\CheckIn\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/check-in-batches response body (stage-09 plan, Endpoints
 * "POST /v1/check-in-batches"). Always 200: per-scan problems never
 * fail the batch wholesale.
 */
#[MapName(SnakeCaseMapper::class)]
class BatchResultData extends Data
{
    /**
     * @param  array<int, ScanOutcomeData>  $results
     */
    public function __construct(
        #[DataCollectionOf(ScanOutcomeData::class)]
        public array $results,
    ) {}
}
