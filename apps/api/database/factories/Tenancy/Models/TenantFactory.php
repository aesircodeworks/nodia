<?php

namespace Database\Factories\Tenancy\Models;

use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'branding_settings' => [],
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'enabled_gateways' => [],
            'payout_schedule' => null,
        ];
    }
}
