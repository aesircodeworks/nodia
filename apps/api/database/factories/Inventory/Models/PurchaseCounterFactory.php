<?php

namespace Database\Factories\Inventory\Models;

use App\Inventory\Models\PurchaseCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseCounter>
 */
class PurchaseCounterFactory extends Factory
{
    protected $model = PurchaseCounter::class;

    /**
     * tenant_id, customer_id, and ticket_type_id have no default: callers
     * pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantity' => 0,
        ];
    }
}
