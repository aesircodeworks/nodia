<?php

namespace Database\Factories\EventCatalog\Models;

use App\EventCatalog\Models\TicketType;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    /**
     * tenant_id and event_id have no default, mirroring VenueFactory/
     * EventFactory: callers pass real ids explicitly. requires_seat is
     * omitted so the ticket_types.requires_seat DEFAULT (false) applies,
     * mirroring EventFactory's own status-DEFAULT precedent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['General Admission', 'VIP', 'Early Bird']),
            'price' => Money::of(fake()->numberBetween(1000, 50000), 'USD'),
            'sales_start' => null,
            'sales_end' => null,
        ];
    }

    public function requiringSeat(): static
    {
        return $this->state(fn (): array => ['requires_seat' => true]);
    }
}
