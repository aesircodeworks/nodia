<?php

namespace Database\Factories\Orders\Models;

use App\Orders\Enums\PromoCodeDiscountType;
use App\Orders\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    protected $model = PromoCode::class;

    /**
     * tenant_id has no default: callers pass a real id explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('PROMO##??')),
            'discount_type' => PromoCodeDiscountType::Percentage,
            'discount_value' => 1000,
            'currency' => null,
            'usage_limit' => null,
            'usage_count' => 0,
            'valid_from' => null,
            'valid_to' => null,
        ];
    }
}
