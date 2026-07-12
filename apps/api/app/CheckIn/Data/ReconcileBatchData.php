<?php

namespace App\CheckIn\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/check-in-batches request body (stage-09 plan, Endpoints
 * "POST /v1/check-in-batches"). The 500-scan ceiling is enforced by
 * App\CheckIn\Actions\ReconcileOfflineScans (BatchTooLargeException),
 * not by a rules() max here: the plan requires the dedicated
 * batch_too_large code rather than the generic
 * request.validation_failed shape a rules()-level max would produce.
 */
#[MapName(SnakeCaseMapper::class)]
class ReconcileBatchData extends Data
{
    /**
     * @param  array<int, OfflineScanData>  $scans
     */
    public function __construct(
        public string $deviceId,
        #[DataCollectionOf(OfflineScanData::class)]
        public array $scans,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:255'],
            'scans' => ['required', 'array'],
        ];
    }
}
