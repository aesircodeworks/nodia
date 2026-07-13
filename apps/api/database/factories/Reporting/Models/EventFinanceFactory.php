<?php

namespace Database\Factories\Reporting\Models;

use App\Reporting\Models\EventFinance;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventFinance>
 */
class EventFinanceFactory extends Factory
{
    protected $model = EventFinance::class;

    /**
     * tenant_id and event_id have no default, mirroring
     * DailySalesFactory: callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'orders_paid_count' => 0,
            'refunds_count' => 0,
            'gross' => Money::of(0, 'USD'),
            'gateway_fee' => Money::of(0, 'USD'),
            'platform_commission' => Money::of(0, 'USD'),
            'tenant_net' => Money::of(0, 'USD'),
            'refunded' => Money::of(0, 'USD'),
        ];
    }
}
