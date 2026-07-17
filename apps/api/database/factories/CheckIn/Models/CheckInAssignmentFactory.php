<?php

namespace Database\Factories\CheckIn\Models;

use App\CheckIn\Models\CheckInAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckInAssignment>
 */
class CheckInAssignmentFactory extends Factory
{
    protected $model = CheckInAssignment::class;

    /**
     * tenant_id, event_id, and user_id have no default: callers pass
     * real ids explicitly, mirroring CheckInFactory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }
}
