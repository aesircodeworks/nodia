<?php

namespace App\Reporting\Data;

use App\Reporting\Models\EventAttendance;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One report_event_attendance row's wire shape (stage-11 plan, Endpoints
 * "EventAttendanceData"): event_id, ticket_type_id, checked_in_count,
 * duplicate_scan_count, first_scan_at, last_scan_at. first_scan_at and
 * last_scan_at are nullable ISO 8601 UTC timestamps: null when the
 * cell's only increments so far are duplicate scans (the
 * report_event_attendance migration's own note), formatted the same way
 * App\Payments\Data\PayoutData formats its own nullable executed_at and
 * reconciled_at.
 *
 * event_id and ticket_type_id are deliberately snake_case in PHP (not
 * the usual camelCase eventId/ticketTypeId), the same reason
 * App\Reporting\Data\DailySalesData keeps sales_date snake_case and
 * App\Reporting\Data\EventFinanceData keeps event_id snake_case:
 * Illuminate\Pagination\AbstractCursorPaginator::getParametersForItem()
 * reads the cursor column's value off the *transformed* paginator item
 * by plain PHP property access matching the literal orderBy column name.
 * report_event_attendance's own unique(tenant_id, event_id,
 * ticket_type_id) constraint makes the pair (event_id, ticket_type_id)
 * fully deterministic under RLS tenant scoping, and both columns are
 * already real wire fields, so no hidden id tiebreak property is needed
 * here (unlike DailySalesData, whose own unique constraint needs a
 * fourth column, sales_date, that isn't itself unique per event and
 * ticket type).
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class EventAttendanceData extends Data
{
    public function __construct(
        public string $event_id,
        public string $ticket_type_id,
        public int $checkedInCount,
        public int $duplicateScanCount,
        public ?string $firstScanAt,
        public ?string $lastScanAt,
    ) {}

    public static function fromModel(EventAttendance $row): self
    {
        return new self(
            $row->event_id,
            $row->ticket_type_id,
            $row->checked_in_count,
            $row->duplicate_scan_count,
            $row->first_scan_at === null ? null : CarbonImmutable::instance($row->first_scan_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $row->last_scan_at === null ? null : CarbonImmutable::instance($row->last_scan_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
