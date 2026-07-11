<?php

namespace Database\Factories\Orders\Models;

use App\Orders\Enums\TicketStatus;
use App\Orders\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    /**
     * tenant_id, order_id, ticket_type_id, and event_id have no
     * default: callers pass real ids explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_seat_id' => null,
            'status' => TicketStatus::Issued,
            'attendee_name' => null,
            'issued_at' => now(),
            'qr_rotation_counter' => 0,
        ];
    }
}
