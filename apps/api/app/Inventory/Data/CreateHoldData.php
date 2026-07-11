<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/storefront/holds request body (stage-06 plan, Endpoints).
 * customer_id is deliberately not a property here: a client-supplied
 * identity assertion is not trusted alone (api-conventions), so it is
 * never read from the body at all. App\Inventory\Http\Controllers\
 * HoldController derives it from the optional customer bearer token
 * instead and hands it to App\Inventory\Actions\CreateHold as a separate
 * argument; a body-supplied customer_id is simply absent from this
 * class's constructor and so has no effect, regardless of what the
 * request payload carries.
 */
#[MapName(SnakeCaseMapper::class)]
class CreateHoldData extends Data
{
    /**
     * @param  list<HoldItemInputData>  $items
     */
    public function __construct(
        public string $eventId,
        #[DataCollectionOf(HoldItemInputData::class)]
        public array $items,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
        ];
    }
}
