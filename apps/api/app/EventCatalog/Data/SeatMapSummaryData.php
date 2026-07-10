<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Models\SeatMap;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * List-row shape for GET /v1/venues/{venue}/seat-maps (stage-05b plan,
 * Endpoints: "paginated SeatMapSummaryData (id, venue_id, name,
 * seat_count, created_at, updated_at)"), built ahead of that endpoint
 * (task-03) alongside its sibling Data objects (task breakdown item 2).
 * No seats: this shape never carries the full document, unlike
 * SeatMapData.
 */
#[MapName(SnakeCaseMapper::class)]
class SeatMapSummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $venueId,
        public string $name,
        public int $seatCount,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * Prefers the seats_count attribute SeatMapController::index()'s own
     * withCount('seats') already loads, avoiding an N+1 count query per
     * page row; falls back to a direct count for callers that pass a
     * model without it loaded.
     */
    public static function fromModel(SeatMap $seatMap): self
    {
        return new self(
            $seatMap->id,
            $seatMap->venue_id,
            $seatMap->name,
            $seatMap->seats_count ?? $seatMap->seats()->count(),
            CarbonImmutable::instance($seatMap->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($seatMap->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
