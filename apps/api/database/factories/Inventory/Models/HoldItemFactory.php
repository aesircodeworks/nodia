<?php

namespace Database\Factories\Inventory\Models;

use App\Inventory\Models\HoldItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HoldItem>
 */
class HoldItemFactory extends Factory
{
    protected $model = HoldItem::class;

    /**
     * tenant_id, hold_id, and ticket_type_id have no default: callers
     * pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantity' => 1,
        ];
    }
}
