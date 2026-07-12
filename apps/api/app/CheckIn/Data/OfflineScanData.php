<?php

namespace App\CheckIn\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One queued scan inside a POST /v1/check-in-batches request (stage-09
 * plan, Endpoints "POST /v1/check-in-batches"). device_id lives on the
 * enclosing App\CheckIn\Data\ReconcileBatchData, not here, since every
 * scan in one batch comes from the same device.
 */
#[MapName(SnakeCaseMapper::class)]
class OfflineScanData extends Data
{
    public function __construct(
        public string $clientScanId,
        public string $qrPayload,
        public string $scannedAt,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'client_scan_id' => ['required', 'string', 'max:255'],
            'qr_payload' => ['required', 'string'],
            'scanned_at' => ['required', 'date'],
        ];
    }
}
