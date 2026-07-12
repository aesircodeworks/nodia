<?php

namespace App\CheckIn\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/check-ins request body (stage-09 plan, Endpoints "POST
 * /v1/check-ins"). scannedAt is the client-attested scan instant (the
 * device clock), distinct from synced_at, which App\CheckIn\Actions\
 * RecordScan stamps at insert time.
 */
#[MapName(SnakeCaseMapper::class)]
class RecordScanData extends Data
{
    public function __construct(
        public string $qrPayload,
        public string $deviceId,
        public string $clientScanId,
        public string $scannedAt,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'qr_payload' => ['required', 'string'],
            'device_id' => ['required', 'string', 'max:255'],
            'client_scan_id' => ['required', 'string', 'uuid'],
            'scanned_at' => ['required', 'date'],
        ];
    }
}
