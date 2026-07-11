<?php

namespace App\Inventory\Events;

use App\Inventory\Models\Hold;
use App\Inventory\Models\HoldItem;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for HoldReleased (stage-06 plan, Domain events:
 * "hold_id, event_id, items, seat_ids"). seat_ids is always empty in this
 * task: seated hold wiring is a later task in this stage. Hidden from
 * TypeScript generation: event payloads are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class HoldReleasedPayload extends Data
{
    /**
     * @param  list<HoldItemPayload>  $items
     * @param  list<string>  $seatIds
     */
    public function __construct(
        public string $holdId,
        public string $eventId,
        #[DataCollectionOf(HoldItemPayload::class)]
        public array $items,
        public array $seatIds,
    ) {}

    /**
     * @param  list<string>  $seatIds
     */
    public static function fromHold(Hold $hold, array $seatIds = []): self
    {
        return new self(
            $hold->id,
            $hold->event_id,
            $hold->items->map(fn (HoldItem $item): HoldItemPayload => new HoldItemPayload($item->ticket_type_id, $item->quantity))->all(),
            $seatIds,
        );
    }
}
