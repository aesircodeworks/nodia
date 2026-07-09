<?php

namespace Database\Factories\Identity\Models;

use App\Identity\Enums\MembershipScope;
use App\Identity\Models\Membership;
use App\Identity\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * Define the model's default state.
     *
     * tenant_id has no default: App\Tenancy\Models\Tenant is a different
     * bounded context's model, and ContextBoundariesTest forbids any
     * class outside App\Tenancy (including this factory) from using it.
     * Callers pass a real tenant id explicitly, the way role_id should
     * usually also match that same tenant even though nothing but the
     * calling Action enforces it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role_id' => Role::factory(),
            'scope' => MembershipScope::Tenant,
        ];
    }

    /**
     * A platform administrator: pinned to the sentinel platform tenant,
     * never NULL (data-conventions Tenancy, stage-03 plan Data model).
     */
    public function platform(): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => config()->string('tenancy.platform_tenant_id'),
            'scope' => MembershipScope::Platform,
        ]);
    }
}
