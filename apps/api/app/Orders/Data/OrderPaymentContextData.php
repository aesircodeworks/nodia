<?php

namespace App\Orders\Data;

use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Support\Money\Money;

/**
 * The internal order facts Payments needs to offer methods and initiate
 * a payment (stage-08a plan, Endpoints); never serialized to the wire.
 */
final class OrderPaymentContextData
{
    public function __construct(
        public readonly string $id,
        public readonly OrderStatus $status,
        public readonly string $eventId,
        public readonly string $holdId,
        public readonly Money $total,
    ) {}

    public static function fromModel(Order $order): self
    {
        return new self($order->id, $order->status, $order->event_id, $order->hold_id, $order->total);
    }
}
