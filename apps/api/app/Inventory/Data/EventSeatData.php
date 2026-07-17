<?php

namespace App\Inventory\Data;

use App\Inventory\Models\EventSeat;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One row of GET /v1/events/{event}/seats and one element of PATCH
 * /v1/events/{event}/seats's response (stage-06 plan, Endpoints: "GET
 * /v1/events/{event}/seats ... returns EventSeatData[] with full
 * statuses including held, sold, blocked, and hold_id"). The admin-only
 * shape: unlike App\Inventory\Data\StorefrontEventSeatData, statuses are
 * never collapsed and hold_id is exposed, since this route requires the
 * events.manage_seating capability rather than being a guest-facing
 * read.
 */
#[MapName(SnakeCaseMapper::class)]
class EventSeatData extends Data
{
    public function __construct(
        public string $eventSeatId,
        public string $eventId,
        public string $seatId,
        public ?string $ticketTypeId,
        public string $status,
        public ?string $holdId,
    ) {}

    public static function fromModel(EventSeat $eventSeat): self
    {
        return new self(
            $eventSeat->id,
            $eventSeat->event_id,
            $eventSeat->seat_id,
            $eventSeat->ticket_type_id,
            $eventSeat->status->value,
            $eventSeat->hold_id,
        );
    }
}
