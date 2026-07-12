<?php

namespace App\Orders\Actions;

use App\Orders\Enums\OrderStatus;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Orders\Exceptions\OrderNotFoundException;
use App\Orders\Models\Order;

/**
 * paid or partially_refunded to refunded (system-design 7.1 as amended
 * by stage-08b: the final partial that exhausts the payment closes the
 * refund arc). One conditional UPDATE guarded on the from-set and
 * checked by affected-row count.
 */
final class MarkOrderRefunded
{
    public function __invoke(string $orderId): Order
    {
        $affected = Order::query()
            ->whereKey($orderId)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded])
            ->update(['status' => OrderStatus::Refunded]);

        if ($affected === 1) {
            return Order::query()->findOrFail($orderId);
        }

        Order::query()->whereKey($orderId)->exists() ?: throw OrderNotFoundException::forId($orderId);

        throw InvalidOrderTransitionException::toStatus($orderId, OrderStatus::Refunded);
    }
}
