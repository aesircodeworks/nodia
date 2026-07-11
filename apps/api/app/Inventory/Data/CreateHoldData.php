<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

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
 *
 * seat_ids is a flat, top-level list (stage-06 plan, Endpoints: "items as
 * [{ticket_type_id, quantity}], optional seat_ids"), Optional so an
 * all-GA request can omit it entirely. App\Inventory\Actions\CreateHold
 * partitions it positionally across the requires_seat items in the order
 * they appear in items: the first requires_seat item's own quantity
 * claims the first slice, the next requires_seat item's own quantity
 * claims the next slice, and so on.
 */
#[MapName(SnakeCaseMapper::class)]
class CreateHoldData extends Data
{
    /**
     * @param  list<HoldItemInputData>  $items
     * @param  list<string>|Optional  $seatIds
     */
    public function __construct(
        public string $eventId,
        #[DataCollectionOf(HoldItemInputData::class)]
        public array $items,
        public array|Optional $seatIds,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'seat_ids' => ['sometimes', 'array'],
            'seat_ids.*' => ['uuid'],
        ];
    }
}
