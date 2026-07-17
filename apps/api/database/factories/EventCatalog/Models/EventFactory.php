<?php

namespace Database\Factories\EventCatalog\Models;

use App\EventCatalog\Data\AsyncPaymentPolicyData;
use App\EventCatalog\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * tenant_id has no default, mirroring VenueFactory/CustomerFactory/
     * RoleFactory: callers pass a real tenant id explicitly. Defaults to a
     * virtual event so a bare Event::factory()->create() needs no Venue
     * row to satisfy events_venue_or_url; atVenue() switches to the
     * physical shape that CHECK (and Event::assertVenueOrUrlInvariant)
     * both require instead. status is omitted so the events.status
     * DEFAULT ('draft') applies (stage-05a plan, Data model).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startAt = fake()->dateTimeBetween('+1 week', '+2 weeks');
        $endAt = (clone $startAt)->modify('+3 hours');

        return [
            'name' => ['en' => fake()->sentence(3)],
            'description' => ['en' => fake()->paragraph()],
            'start_at' => $startAt,
            'end_at' => $endAt,
            'timezone' => 'UTC',
            'is_virtual' => true,
            'venue_id' => null,
            'virtual_event_url' => fake()->url(),
            'async_payment_policy' => new AsyncPaymentPolicyData,
        ];
    }

    public function atVenue(string $venueId): static
    {
        return $this->state(fn (): array => [
            'is_virtual' => false,
            'venue_id' => $venueId,
            'virtual_event_url' => null,
        ]);
    }
}
