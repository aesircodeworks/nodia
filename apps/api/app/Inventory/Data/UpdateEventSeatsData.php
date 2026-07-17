<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * PATCH /v1/events/{event}/seats request body (stage-06 plan,
 * Endpoints). An unknown op is rejected by
 * App\Inventory\Data\UpdateEventSeatOperationData's own rules() with 422
 * request.validation_failed, before any operation reaches
 * App\Inventory\Actions\UpdateEventSeats.
 */
#[MapName(SnakeCaseMapper::class)]
class UpdateEventSeatsData extends Data
{
    /**
     * @param  list<UpdateEventSeatOperationData>  $operations
     */
    public function __construct(
        #[DataCollectionOf(UpdateEventSeatOperationData::class)]
        public array $operations,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'operations' => ['required', 'array', 'min:1'],
        ];
    }
}
