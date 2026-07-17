<?php

namespace Database\Factories\EventCatalog\Models;

use App\EventCatalog\Models\Seat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seat>
 */
class SeatFactory extends Factory
{
    protected $model = Seat::class;

    /**
     * tenant_id and seat_map_id have no default, mirroring VenueFactory/
     * TicketTypeFactory: callers pass real ids explicitly. number is
     * unique (RoleFactory's own precedent for a natural-key column) so
     * repeated calls against the same seat map do not collide on the
     * (seat_map_id, section, row, number) unique index without every
     * caller having to override it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'section' => 'A',
            'row' => '1',
            'number' => (string) fake()->unique()->numberBetween(1, 1_000_000),
            'position_x' => null,
            'position_y' => null,
        ];
    }
}
