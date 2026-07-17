<?php

namespace Database\Factories\Identity\Models;

use App\Identity\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * tenant_id has no default, mirroring MembershipFactory/RoleFactory:
     * App\Tenancy\Models\Tenant is a different bounded context's model,
     * and ContextBoundariesTest forbids any class outside App\Tenancy
     * (including this factory) from using it. Callers pass a real tenant
     * id explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
        ];
    }
}
