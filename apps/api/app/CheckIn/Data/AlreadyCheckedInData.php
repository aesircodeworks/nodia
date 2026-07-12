<?php

namespace App\CheckIn\Data;

/**
 * Carries the surviving accepted scan's timestamp and device from
 * App\CheckIn\Actions\RecordScan to its controller, for the 409
 * ticket_already_checked_in problem document's first_scanned_at and
 * first_device_id extension members (stage-09 plan, Endpoints "POST
 * /v1/check-ins"). Never thrown as an exception; see
 * App\CheckIn\Data\RecordScanOutcome.
 */
final readonly class AlreadyCheckedInData
{
    public function __construct(
        public string $firstScannedAt,
        public string $firstDeviceId,
    ) {}
}
