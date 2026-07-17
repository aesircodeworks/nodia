<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * PATCH /v1/events/{event}/seats response body (stage-06 plan,
 * Endpoints): the seats affected by the applied operations, in request
 * order. Object-wrapped rather than a bare array, mirroring
 * App\Inventory\Data\StorefrontEventSeatMapData's own precedent, so the
 * OpenAPI response schema can declare additionalProperties: false
 * (ADR 019: response conformance assertions only catch Data-class drift
 * when the spec schemas are strict).
 */
#[MapName(SnakeCaseMapper::class)]
class EventSeatBatchData extends Data
{
    /**
     * @param  list<EventSeatData>  $seats
     */
    public function __construct(
        #[DataCollectionOf(EventSeatData::class)]
        public array $seats,
    ) {}
}
