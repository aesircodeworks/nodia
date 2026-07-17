<?php

namespace Database\Factories\Inventory\Models;

use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSeat>
 */
class EventSeatFactory extends Factory
{
    protected $model = EventSeat::class;

    /**
     * tenant_id, event_id, and seat_id have no default, mirroring
     * HoldFactory: callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_type_id' => null,
            'status' => EventSeatStatus::Available,
            'hold_id' => null,
        ];
    }
}
