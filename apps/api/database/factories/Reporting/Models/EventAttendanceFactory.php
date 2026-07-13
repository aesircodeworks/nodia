<?php

namespace Database\Factories\Reporting\Models;

use App\Reporting\Models\EventAttendance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventAttendance>
 */
class EventAttendanceFactory extends Factory
{
    protected $model = EventAttendance::class;

    /**
     * tenant_id, event_id, and ticket_type_id have no default, mirroring
     * DailySalesFactory and EventFinanceFactory: callers pass real ids
     * explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checked_in_count' => 0,
            'duplicate_scan_count' => 0,
            'first_scan_at' => null,
            'last_scan_at' => null,
        ];
    }
}
