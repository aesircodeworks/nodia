<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One ticket type's entry in an EventAvailabilityData response (stage-06
 * plan, Endpoints "GET /v1/storefront/events/{event}/availability"):
 * available = quantity - sold - held, floored at 0; on_sale reflects the
 * ticket type's own sales window against the current time.
 */
#[MapName(SnakeCaseMapper::class)]
class TicketTypeAvailabilityData extends Data
{
    public function __construct(
        public string $ticketTypeId,
        public int $available,
        public bool $onSale,
    ) {}
}
