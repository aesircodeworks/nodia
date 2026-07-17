<?php

namespace Database\Factories\Orders\Models;

use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * tenant_id, customer_id, and event_id have no default, mirroring
     * HoldFactory: callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hold_id' => Str::uuid7()->toString(),
            'status' => OrderStatus::Pending,
            'subtotal' => Money::of(5000, 'USD'),
            'discount' => Money::of(0, 'USD'),
            'fees' => Money::of(0, 'USD'),
            'total' => Money::of(5000, 'USD'),
        ];
    }
}
