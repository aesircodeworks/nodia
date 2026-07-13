<?php

namespace App\Reporting\Support;

use Carbon\CarbonImmutable;

/**
 * The commutative increment one TicketCheckedIn or DuplicateScanDetected
 * event contributes to one report_event_attendance cell (stage-11 plan,
 * Domain events "Consumed" table; task 11): a checked-in scan touches
 * only the checked-in count and both scan-timestamp bounds, a duplicate
 * scan touches only the duplicate count, so unordered delivery and
 * replay converge regardless of which arrives first.
 * ApplyEventAttendanceIncrement folds the timestamp bounds through
 * LEAST/GREATEST, so a duplicate()'s null timestamps never move an
 * already-set bound (Postgres LEAST/GREATEST ignore NULL arguments).
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

    public static function duplicate(string $eventId, string $ticketTypeId): self
    {
        return new self($eventId, $ticketTypeId, 0, 1, null, null);
    }
}
