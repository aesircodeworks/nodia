<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * GET /v1/storefront/events/{event}/availability response shape
 * (stage-06 plan, Endpoints).
 */
#[MapName(SnakeCaseMapper::class)]
class EventAvailabilityData extends Data
{
    /**
     * @param  list<TicketTypeAvailabilityData>  $ticketTypes
     */
    public function __construct(
        public string $eventId,
        #[DataCollectionOf(TicketTypeAvailabilityData::class)]
        public array $ticketTypes,
    ) {}
}
