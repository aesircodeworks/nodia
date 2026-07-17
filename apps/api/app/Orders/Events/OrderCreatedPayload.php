<?php

namespace App\Orders\Events;

use App\Orders\Models\Order;
use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for OrderCreated (stage-07 plan, Domain
 * events): identifiers and facts only, no entity snapshot
 * (event-conventions). Hidden from TypeScript generation: event
 * payloads are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class OrderCreatedPayload extends Data
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public string $eventId,
        public string $holdId,
        public ?string $promoCodeId,
        public string $status,
        public Money $subtotal,
        public Money $discount,
        public Money $fees,
        public Money $total,
    ) {}

    public static function fromOrder(Order $order): self
    {
        return new self(
            $order->id,
            $order->customer_id,
            $order->event_id,
            $order->hold_id,
            $order->promo_code_id,
            $order->status->value,
            $order->subtotal,
            $order->discount,
            $order->fees,
            $order->total,
        );
    }
}
