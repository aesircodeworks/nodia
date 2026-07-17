<?php

namespace Database\Factories\Inventory\Models;

use App\Inventory\Models\TicketTypeInventory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketTypeInventory>
 */
class TicketTypeInventoryFactory extends Factory
{
    protected $model = TicketTypeInventory::class;

    /**
     * tenant_id and ticket_type_id have no default, mirroring
     * TicketTypeFactory: callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantity' => fake()->numberBetween(10, 500),
            'held' => 0,
            'sold' => 0,
        ];
    }
}
