<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * GET /v1/storefront/events/{event}/seats response body (stage-06 plan,
 * Endpoints). Unpaginated: a seat map is only useful whole (stage-06
 * plan, Risks: "Storefront seat map payload size").
 */
#[MapName(SnakeCaseMapper::class)]
class StorefrontEventSeatMapData extends Data
{
    /**
     * @param  list<StorefrontEventSeatData>  $seats
     */
    public function __construct(
        public string $eventId,
        #[DataCollectionOf(StorefrontEventSeatData::class)]
        public array $seats,
    ) {}
}
