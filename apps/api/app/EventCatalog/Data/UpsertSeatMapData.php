<?php

namespace App\EventCatalog\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/venues/{venue}/seat-maps request body (stage-05b plan,
 * Endpoints), and, from task-04 onward, the same body for PUT
 * /v1/seat-maps/{seat_map}: a seat map plus its seats is one document
 * (system-design 3.2, UpsertSeatMap Action). seats is left out of
 * rules() below on purpose: leaving it unmentioned keeps its automatic
 * "required, array" inference active, and the nested SeatInputData.*
 * rules (section, row, number, position_x, position_y) can only come
 * from SeatInputData's own rules() method, never from here.
 */
#[MapName(SnakeCaseMapper::class)]
class UpsertSeatMapData extends Data
{
    /**
     * @param  array<string, mixed>  $layout
     * @param  list<SeatInputData>  $seats
     */
    public function __construct(
        public string $name,
        public array $layout,
        #[DataCollectionOf(SeatInputData::class)]
        public array $seats,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'filled', 'max:255'],
            // 'present', not 'required': layout is opaque map-level
            // geometry (stage-05b plan, Data model) a freshly created
            // template may not have populated yet, and Laravel's
            // 'required' rule (unlike 'present') rejects an empty array,
            // which would wrongly forbid {} as a starting layout.
            'layout' => ['present', 'array'],
        ];
    }
}
