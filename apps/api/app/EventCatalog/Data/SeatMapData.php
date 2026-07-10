<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Models\SeatMap;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Full seat map document (stage-05b plan, Endpoints: POST/GET response
 * shape). seats is never Optional here, unlike EventData's ticketTypes:
 * a seat map without its seats is not the documented shape of this
 * endpoint pair, so fromModel() always expects the seats relation
 * loaded and ordered (SeatMap::seats()'s own default ordering).
 */
#[MapName(SnakeCaseMapper::class)]
class SeatMapData extends Data
{
    /**
     * @param  array<string, mixed>  $layout
     * @param  DataCollection<int, SeatData>  $seats
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $venueId,
        public string $name,
        public array $layout,
        #[DataCollectionOf(SeatData::class)]
        public DataCollection $seats,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(SeatMap $seatMap): self
    {
        return new self(
            $seatMap->id,
            $seatMap->tenant_id,
            $seatMap->venue_id,
            $seatMap->name,
            $seatMap->layout,
            SeatData::collect($seatMap->seats, DataCollection::class),
            CarbonImmutable::instance($seatMap->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($seatMap->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
