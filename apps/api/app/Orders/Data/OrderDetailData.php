<?php

namespace App\Orders\Data;

use App\Identity\Data\CustomerSummaryData;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * GET /v1/orders/{order} response shape (stage-07 plan, Endpoints):
 * the order fields plus tickets and a customer summary composed
 * through Identity's ResolveCustomerSummary Action, never a join.
 */
#[MapName(SnakeCaseMapper::class)]
class OrderDetailData extends Data
{
    /**
     * @param  list<OrderItemData>  $items
     * @param  list<TicketData>  $tickets
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
        #[DataCollectionOf(TicketData::class)]
        public array $tickets,
        public ?CustomerSummaryData $customer,
    ) {}

    /**
     * @param  list<TicketData>  $tickets
     */
    public static function fromModel(Order $order, array $tickets, ?CustomerSummaryData $customer): self
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
            $order->promo_code_id === null ? null : $order->promoCode?->code,
            CarbonImmutable::instance($order->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $tickets,
            $customer,
        );
    }
}
