<?php

namespace App\Inventory\Events;

use App\Inventory\Models\Hold;
use App\Inventory\Models\HoldItem;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for HoldCreated (stage-06 plan, Domain events:
 * "hold_id, event_id, customer_id (nullable), expires_at, items as
 * [{ticket_type_id, quantity}], seat_ids"). seat_ids is always empty in
 * this task (seated hold wiring is a later task in this stage). Hidden
 * from TypeScript generation: event payloads are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class HoldCreatedPayload extends Data
{
    /**
     * @param  list<HoldCreatedItemPayload>  $items
     * @param  list<string>  $seatIds
     */
    public function __construct(
        public string $holdId,
        public string $eventId,
        public ?string $customerId,
        public string $expiresAt,
        #[DataCollectionOf(HoldCreatedItemPayload::class)]
        public array $items,
        public array $seatIds,
    ) {}

    public static function fromHold(Hold $hold): self
    {
        return new self(
            $hold->id,
            $hold->event_id,
            $hold->customer_id,
            CarbonImmutable::instance($hold->expires_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $hold->items->map(fn (HoldItem $item): HoldCreatedItemPayload => new HoldCreatedItemPayload($item->ticket_type_id, $item->quantity))->all(),
            [],
        );
    }
}
