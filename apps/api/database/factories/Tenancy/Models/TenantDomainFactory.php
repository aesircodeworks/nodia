<?php

namespace Database\Factories\Tenancy\Models;

use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantDomain>
 */
class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'domain' => strtolower(fake()->unique()->domainName()),
            'is_primary' => false,
        ];
    }
}
