<?php

namespace App\Reporting\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `INSERT ... ON CONFLICT (tenant_id, event_id, ticket_type_id) DO
 * UPDATE SET ... = report_event_attendance.column + excluded.column,
 * first_scan_at = LEAST(...), last_scan_at = GREATEST(...)` (stage-11
 * plan, Data model "report_event_attendance"): the counts are additive
 * increments evaluated in the statement, never a read-then-write
 * (master plan test-first rule 2); the two timestamp bounds are folded
 * through LEAST/GREATEST in the same statement so they stay commutative
 * under unordered delivery and replay (stage-11 plan, Data model
 * "report_event_attendance": "LEAST and GREATEST keep the timestamp
 * columns commutative so replay order cannot change them"). Postgres's
 * LEAST/GREATEST ignore NULL arguments (returning NULL only when every
 * argument is NULL), so an increment's NULL timestamps (a
 * DuplicateScanDetected increment carries none) never overwrite an
 * already-set bound, and an insert whose only increment so far is a
 * duplicate leaves both bounds NULL until a checked-in scan arrives.
 */
final class ApplyEventAttendanceIncrement
{
    public function __invoke(string $tenantId, EventAttendanceIncrement $increment): void
    {
        $now = Date::now();

        DB::statement(
            <<<'SQL'
            insert into report_event_attendance (
                id, tenant_id, event_id, ticket_type_id,
                checked_in_count, duplicate_scan_count, first_scan_at, last_scan_at,
                created_at, updated_at
            )
            values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            on conflict (tenant_id, event_id, ticket_type_id)
            do update set
                checked_in_count = report_event_attendance.checked_in_count + excluded.checked_in_count,
                duplicate_scan_count = report_event_attendance.duplicate_scan_count + excluded.duplicate_scan_count,
                first_scan_at = least(report_event_attendance.first_scan_at, excluded.first_scan_at),
                last_scan_at = greatest(report_event_attendance.last_scan_at, excluded.last_scan_at),
                updated_at = excluded.updated_at
            SQL,
            [
                (string) Str::uuid7(),
                $tenantId,
                $increment->eventId,
                $increment->ticketTypeId,
                $increment->checkedInCount,
                $increment->duplicateScanCount,
                $increment->firstScanAt,
                $increment->lastScanAt,
                $now,
                $now,
            ],
        );
    }
}
