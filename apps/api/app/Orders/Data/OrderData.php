<?php

namespace App\Orders\Data;

use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The order wire shape shared by the buyer and staff read surfaces
 * (stage-07 plan, Endpoints): id, status, event_id, items, the four
 * money fields, promo_code as the human-facing code string (raw ids are
 * never shown to end users), created_at. customer_id is deliberately
 * absent from the buyer shape.
 */
#[MapName(SnakeCaseMapper::class)]
class OrderData extends Data
{
    /**
     * @param  list<OrderItemData>  $items
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $eventId,
        #[DataCollectionOf(OrderItemData::class)]
        public array $items,
        public Money $subtotal,
        public Money $discount,
        public Money $fees,
        public Money $total,
        public ?string $promoCode,
        public string $createdAt,
    ) {}

    public static function fromModel(Order $order, ?string $promoCode = null): self
    {
        return new self(
            $order->id,
            $order->status->value,
            $order->event_id,
            $order->items->map(fn (OrderItem $item): OrderItemData => OrderItemData::fromModel($item))->all(),
            $order->subtotal,
            $order->discount,
            $order->fees,
            $order->total,
            $promoCode,
            CarbonImmutable::instance($order->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
