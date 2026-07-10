<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Models\Seat;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * A seat inside a SeatMapData response (stage-05b plan, Endpoints:
 * "SeatData adds id to the input fields"). No timestamps: a seat's own
 * created_at/updated_at is not part of the documented wire shape.
 */
#[MapName(SnakeCaseMapper::class)]
class SeatData extends Data
{
    public function __construct(
        public string $id,
        public string $section,
        public string $row,
        public string $number,
        public ?int $positionX,
        public ?int $positionY,
    ) {}

    public static function fromModel(Seat $seat): self
    {
        return new self(
            $seat->id,
            $seat->section,
            $seat->row,
            $seat->number,
            $seat->position_x,
            $seat->position_y,
        );
    }
}
