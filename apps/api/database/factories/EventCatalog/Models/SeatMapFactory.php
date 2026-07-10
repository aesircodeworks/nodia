<?php

namespace Database\Factories\EventCatalog\Models;

use App\EventCatalog\Models\SeatMap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeatMap>
 */
class SeatMapFactory extends Factory
{
    protected $model = SeatMap::class;

    /**
     * tenant_id and venue_id have no default, mirroring VenueFactory/
     * TicketTypeFactory: callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' Map',
            'layout' => [],
        ];
    }
}
