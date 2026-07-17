<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Models\Venue;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class VenueData extends Data
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public string $address,
        public string $city,
        public string $country,
        public int $capacity,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Venue $venue): self
    {
        return new self(
            $venue->id,
            $venue->tenant_id,
            $venue->name,
            $venue->address,
            $venue->city,
            $venue->country,
            $venue->capacity,
            CarbonImmutable::instance($venue->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            CarbonImmutable::instance($venue->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
