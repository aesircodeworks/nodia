<?php

namespace App\Inventory\Data;

use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\Hold;
use App\Inventory\Models\HoldItem;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal read model App\Inventory\Actions\ResolveHoldForOrder returns
 * to Orders for hold conversion (stage-07 plan, Slice 1), never part of
 * the wire contract. Orders reads hold facts through this seam only,
 * never Inventory's tables (system-design 3.1).
 */
#[Hidden]
class HoldForOrderData extends Data
{
    /**
     * @param  list<HoldForOrderItemData>  $items
     */
    public function __construct(
        public string $id,
        public string $eventId,
        public ?string $customerId,
        public HoldStatus $status,
        public CarbonImmutable $expiresAt,
        public array $items,
    ) {}

    public static function fromModel(Hold $hold): self
    {
        $seatIdsByTicketType = EventSeat::query()
            ->where('hold_id', $hold->id)
            ->get(['id', 'ticket_type_id'])
            ->groupBy('ticket_type_id')
            ->map(fn ($seats) => $seats->pluck('id')->all());

        return new self(
            $hold->id,
            $hold->event_id,
            $hold->customer_id,
            $hold->status,
            CarbonImmutable::instance($hold->expires_at),
            $hold->items->map(fn (HoldItem $item): HoldForOrderItemData => new HoldForOrderItemData(
                $item->ticket_type_id,
                $item->quantity,
                $seatIdsByTicketType->get($item->ticket_type_id, collect())->toArray(),
            ))->all(),
        );
    }
}
