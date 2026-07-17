<?php

namespace Database\Factories\Inventory\Models;

use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hold>
 */
class HoldFactory extends Factory
{
    protected $model = Hold::class;

    /**
     * tenant_id and event_id have no default, mirroring TicketTypeFactory:
     * callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => null,
            'status' => HoldStatus::Active,
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
