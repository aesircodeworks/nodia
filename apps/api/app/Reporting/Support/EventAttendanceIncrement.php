<?php

namespace App\Reporting\Support;

use Carbon\CarbonImmutable;

/**
 * The commutative increment one TicketCheckedIn or DuplicateScanDetected
 * event contributes to one report_event_attendance cell (stage-11 plan,
 * Domain events "Consumed" table; task 11): a checked-in scan touches
 * the checked-in count and both scan-timestamp bounds; a duplicate scan
 * touches the duplicate count and folds the surviving accepted scan's
 * first_scanned_at into the first bound, so unordered delivery and
 * replay converge regardless of which arrives first.
 * ApplyEventAttendanceIncrement folds the timestamp bounds through
 * LEAST/GREATEST, so a duplicate()'s null last bound never moves an
 * already-set one (Postgres LEAST/GREATEST ignore NULL arguments).
 *
 * The duplicate branch carrying first_scanned_at is what keeps
 * first_scan_at honest when offline reconciliation demotes a
 * later-timestamped winner: TicketCheckedIn is recorded only for the
 * very first accepted insert, so the swapped-in earlier scan's instant
 * reaches this projection solely through the DuplicateScanDetected
 * payload's first_scanned_at.
 */
final readonly class EventAttendanceIncrement
{
    private function __construct(
        public string $eventId,
        public string $ticketTypeId,
        public int $checkedInCount,
        public int $duplicateScanCount,
        public ?CarbonImmutable $firstScanAt,
        public ?CarbonImmutable $lastScanAt,
    ) {}

    public static function checkedIn(string $eventId, string $ticketTypeId, CarbonImmutable $scannedAt): self
    {
        return new self($eventId, $ticketTypeId, 1, 0, $scannedAt, $scannedAt);
    }

    public static function duplicate(string $eventId, string $ticketTypeId, CarbonImmutable $firstScanAt): self
    {
        return new self($eventId, $ticketTypeId, 0, 1, $firstScanAt, null);
    }
}
