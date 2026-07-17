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
 * seat_ids is the full set of event_seats ids currently held (or, once
 * committed, sold) for this hold, passed in explicitly by the caller
 * (stage-06 plan, Slice 6) rather than read through an Eloquent relation:
 * App\Inventory\Models\Hold has no eventSeats() relation, mirroring the
 * plain-FK posture the other cross-context columns on this model already
 * use.
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

    /**
     * @param  list<string>  $seatIds
     */
    public static function fromModel(Hold $hold, array $seatIds = []): self
    {
        return new self(
            $hold->id,
            $hold->event_id,
            $hold->status->value,
            CarbonImmutable::instance($hold->expires_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $hold->items->map(fn (HoldItem $item): HoldItemData => HoldItemData::fromModel($item))->all(),
            $seatIds,
        );
    }
}
