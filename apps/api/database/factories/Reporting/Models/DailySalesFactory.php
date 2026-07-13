<?php

namespace Database\Factories\Reporting\Models;

use App\Reporting\Models\DailySales;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailySales>
 */
class DailySalesFactory extends Factory
{
    protected $model = DailySales::class;

    /**
     * tenant_id, event_id, and ticket_type_id have no default, mirroring
     * PurchaseCounterFactory: callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_date' => now()->toDateString(),
            'tickets_issued_count' => 0,
            'tickets_refunded_count' => 0,
            'gross' => Money::of(0, 'USD'),
            'refunded' => Money::of(0, 'USD'),
        ];
    }
}
