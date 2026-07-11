<?php

namespace App\Inventory\Data;

use App\EventCatalog\Data\SeatData;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One seat inside a StorefrontEventSeatMapData response (stage-06 plan,
 * Endpoints "GET /v1/storefront/events/{event}/seats": "per seat
 * {event_seat_id, seat_id, section, row, number, ticket_type_id,
 * status} with held and sold collapsed to unavailable on the storefront
 * wire"). section/row/number come from the SeatData a Catalog Action
 * resolved, never from a join against Catalog's own tables.
 */
#[MapName(SnakeCaseMapper::class)]
class StorefrontEventSeatData extends Data
{
    public function __construct(
        public string $eventSeatId,
        public string $seatId,
        public string $section,
        public string $row,
        public string $number,
        public ?string $ticketTypeId,
        public string $status,
    ) {}

    public static function fromModel(EventSeat $eventSeat, SeatData $seat): self
    {
        return new self(
            $eventSeat->id,
            $eventSeat->seat_id,
            $seat->section,
            $seat->row,
            $seat->number,
            $eventSeat->ticket_type_id,
            self::wireStatus($eventSeat->status),
        );
    }

    private static function wireStatus(EventSeatStatus $status): string
    {
        return match ($status) {
            EventSeatStatus::Held, EventSeatStatus::Sold => 'unavailable',
            EventSeatStatus::Available => 'available',
            EventSeatStatus::Blocked => 'blocked',
        };
    }
}
