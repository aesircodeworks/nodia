<?php

namespace App\Reporting\Models;

use Database\Factories\Reporting\Models\EventAttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One tenant, event, and ticket type's check-in activity (stage-11
 * plan, Data model "report_event_attendance"). A pre-aggregated read
 * model built by the ProjectEventAttendance outbox consumer (task 11),
 * not a source-of-truth table, so $table is set explicitly rather than
 * relying on Eloquent's guess, mirroring DailySales and EventFinance.
 * first_scan_at and last_scan_at are the monotonic min and max scan
 * instants across every TicketCheckedIn this cell has absorbed, kept
 * commutative under replay via LEAST/GREATEST in the projector's
 * upsert. Every mutation is an ON CONFLICT DO UPDATE additive increment
 * issued from the projector, never a bare Eloquent ->save() on this
 * model (master plan test-first rule 2).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $event_id
 * @property string $ticket_type_id
 * @property int $checked_in_count
 * @property int $duplicate_scan_count
 * @property Carbon|null $first_scan_at
 * @property Carbon|null $last_scan_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'tenant_id',
    'event_id',
    'ticket_type_id',
    'checked_in_count',
    'duplicate_scan_count',
    'first_scan_at',
    'last_scan_at',
])]
class EventAttendance extends Model
{
    /** @use HasFactory<EventAttendanceFactory> */
    use HasFactory, HasUuids;

    protected $table = 'report_event_attendance';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_scan_at' => 'datetime',
            'last_scan_at' => 'datetime',
        ];
    }
}
