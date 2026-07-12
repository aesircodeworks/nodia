<?php

namespace App\CheckIn\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for DuplicateScanDetected (stage-09 plan,
 * Domain events "Produced"; system-design 9.3 group 6). Recorded once
 * per duplicate check_ins row insert, whether the losing side of a
 * first-scan-wins online race or a row demoted by a later batch
 * reconciliation swap; never dropped, this is the staff follow-up
 * signal. first_check_in_id, first_scanned_at, and first_device_id name
 * the surviving accepted scan.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class DuplicateScanDetectedPayload extends Data
{
    public function __construct(
        public string $checkInId,
        public string $ticketId,
        public string $eventId,
        public string $deviceId,
        public string $userId,
        public string $scannedAt,
        public string $firstCheckInId,
        public string $firstScannedAt,
        public string $firstDeviceId,
    ) {}
}
