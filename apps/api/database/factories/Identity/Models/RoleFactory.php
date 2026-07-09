<?php

namespace Database\Factories\Identity\Models;

use App\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * Define the model's default state.
     *
     * tenant_id has no default: App\Tenancy\Models\Tenant is a different
     * bounded context's model, and ContextBoundariesTest forbids any
     * class outside App\Tenancy (including this factory) from using it.
     * Callers pass a real tenant id explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'capabilities' => [],
        ];
    }

    /**
     * A global template role: NULL tenant_id, the sanctioned exception
     * (data-conventions Tenancy).
     */
    public function template(): static
    {
        return $this->state(fn (): array => ['tenant_id' => null]);
    }
}
