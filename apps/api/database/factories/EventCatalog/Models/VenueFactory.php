<?php

namespace Database\Factories\EventCatalog\Models;

use App\EventCatalog\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    protected $model = Venue::class;

    /**
     * tenant_id has no default, mirroring CustomerFactory/RoleFactory:
     * callers pass a real tenant id explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Arena',
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => 'US',
            'capacity' => fake()->numberBetween(100, 20000),
        ];
    }
}
