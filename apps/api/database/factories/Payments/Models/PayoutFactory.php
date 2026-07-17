<?php

namespace Database\Factories\Payments\Models;

use App\Payments\Enums\PayoutStatus;
use App\Payments\Models\Payout;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    /**
     * tenant_id has no default, mirroring SubmerchantAccountFactory:
     * callers pass a real id explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gateway' => 'fake',
            'gateway_reference' => Str::uuid7()->toString(),
            'money' => Money::of(5000, 'USD'),
            'status' => PayoutStatus::Pending,
        ];
    }
}
