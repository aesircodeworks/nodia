<?php

namespace App\EventCatalog\Data;

use Closure;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * POST /v1/venues/{venue}/seat-maps request body (stage-05b plan,
 * Endpoints), and, from task-04 onward, the same body for PUT
 * /v1/seat-maps/{seat_map}: a seat map plus its seats is one document
 * (system-design 3.2, UpsertSeatMap Action). The explicit seats rule
 * caps the collection at the configured ceiling; the nested
 * SeatInputData.* rules (section, row, number, position_x, position_y)
 * still come only from SeatInputData's own rules() method (spatie derives
 * DataCollectionOf item rules there regardless of a top-level override),
 * never from here.
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
        #[LiteralTypeScriptType('Record<string, unknown>')]
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
            // which would wrongly forbid {} as a starting layout. The
            // closure rejects a populated JSON array (a PHP list), which
            // the bare 'array' rule accepts but the OpenAPI object schema
            // and the generated Record<string, unknown> type forbid; an
            // empty array is left through since {} and [] are the same
            // decoded value and {} is the valid empty-layout case above.
            'layout' => [
                'present',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_array($value) && $value !== [] && array_is_list($value)) {
                        $fail('The :attribute field must be an object.');
                    }
                },
            ],
            'seats' => ['present', 'array', 'max:'.config()->integer('catalog.seat_map_max_seats')],
        ];
    }
}
