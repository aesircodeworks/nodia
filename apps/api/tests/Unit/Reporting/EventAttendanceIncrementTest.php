<?php

use App\Reporting\Support\EventAttendanceIncrement;
use Carbon\CarbonImmutable;

/*
 * Stage-11 plan, Slice 5 Unit test: payload-to-increment mapping for
 * both check-in event types, proving checkedIn() sets both scan-
 * timestamp bounds to the same instant and duplicate() folds the
 * surviving accepted scan's instant into the first bound only, never
 * the last.
 */

it('maps a checked-in scan to +1 checked_in_count and both scan-timestamp bounds at the scanned instant', function (): void {
    $scannedAt = CarbonImmutable::parse('2026-07-13T12:00:00Z');

    $increment = EventAttendanceIncrement::checkedIn('event-1', 'ticket-type-1', $scannedAt);

    expect($increment->eventId)->toBe('event-1')
        ->and($increment->ticketTypeId)->toBe('ticket-type-1')
        ->and($increment->checkedInCount)->toBe(1)
        ->and($increment->duplicateScanCount)->toBe(0)
        ->and($increment->firstScanAt)->toBe($scannedAt)
        ->and($increment->lastScanAt)->toBe($scannedAt);
});

it('maps a duplicate scan to +1 duplicate_scan_count with the surviving first scan folded into the first bound only', function (): void {
    $firstScanAt = CarbonImmutable::parse('2026-07-13T11:00:00Z');

    $increment = EventAttendanceIncrement::duplicate('event-1', 'ticket-type-1', $firstScanAt);

    expect($increment->eventId)->toBe('event-1')
        ->and($increment->ticketTypeId)->toBe('ticket-type-1')
        ->and($increment->checkedInCount)->toBe(0)
        ->and($increment->duplicateScanCount)->toBe(1)
        ->and($increment->firstScanAt)->toBe($firstScanAt)
        ->and($increment->lastScanAt)->toBeNull();
});
