<?php

namespace App\EventCatalog\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One seat inside an UpsertSeatMapData request (stage-05b plan,
 * Endpoints: "seats as an array of SeatInputData (section, row, number,
 * position_x, position_y)"). Nested inside a parent Data class, so this
 * class's own rules() is the only place its dot-path validation
 * (seats.*.section, etc.) can be declared; the parent cannot override it
 * (spatie/laravel-data: nested object rules are always derived from the
 * nested class's own properties). position_x and position_y are
 * "present, nullable" (required key, nullable value) rather than
 * Optional, mirroring CreateEventData's venue_id precedent, so every
 * seat's shape is uniform on the wire regardless of whether the seat has
 * been placed on the map yet.
 */
#[MapName(SnakeCaseMapper::class)]
class SeatInputData extends Data
{
    public function __construct(
        public string $section,
        public string $row,
        public string $number,
        public ?int $positionX,
        public ?int $positionY,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'section' => ['required', 'string', 'max:255'],
            'row' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:255'],
            'position_x' => ['present', 'nullable', 'integer'],
            'position_y' => ['present', 'nullable', 'integer'],
        ];
    }
}
