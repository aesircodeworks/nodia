<?php

namespace Database\Factories\Orders\Models;

use App\Orders\Models\OrderItem;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * tenant_id, order_id, and ticket_type_id have no default: callers
     * pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantity' => 1,
            'unit_price' => Money::of(5000, 'USD'),
            'attendee_names' => null,
        ];
    }
}
