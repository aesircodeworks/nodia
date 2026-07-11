<?php

namespace App\Inventory\Data;

use App\Inventory\Models\Hold;
use App\Inventory\Models\HoldItem;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST and GET /v1/storefront/holds/{hold} response shape (stage-06
 * plan, Endpoints). customer_id is deliberately excluded: the endpoint
 * table lists only id, event_id, status, expires_at, items, seat_ids.
 * seat_ids is always an empty list in this task: seated hold wiring is a
 * later task in this stage (Slice 6), and the field ships now so the
 * response envelope does not change shape once seats land.
 */
#[MapName(SnakeCaseMapper::class)]
class HoldData extends Data
{
    /**
     * @param  list<HoldItemData>  $items
     * @param  list<string>  $seatIds
     */
    public function __construct(
        public string $id,
        public string $eventId,
        public string $status,
        public string $expiresAt,
        #[DataCollectionOf(HoldItemData::class)]
        public array $items,
        public array $seatIds,
    ) {}

    public static function fromModel(Hold $hold): self
    {
        return new self(
            $hold->id,
            $hold->event_id,
            $hold->status->value,
            CarbonImmutable::instance($hold->expires_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $hold->items->map(fn (HoldItem $item): HoldItemData => HoldItemData::fromModel($item))->all(),
            [],
        );
    }
}
