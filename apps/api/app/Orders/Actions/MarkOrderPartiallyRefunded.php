<?php

namespace App\Orders\Actions;

use App\Orders\Enums\OrderStatus;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Orders\Exceptions\OrderNotFoundException;
use App\Orders\Models\Order;

/**
 * paid or partially_refunded to partially_refunded (system-design 7.1
 * as amended by stage-08b: further partial refunds re-enter the same
 * state). One conditional UPDATE guarded on the from-set and checked by
 * affected-row count, mirroring TransitionsOrderStatus for the
 * multi-from refund arcs.
 */
final class MarkOrderPartiallyRefunded
{
    public function __invoke(string $orderId): Order
    {
        $affected = Order::query()
            ->whereKey($orderId)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded])
            ->update(['status' => OrderStatus::PartiallyRefunded]);

        if ($affected === 1) {
            return Order::query()->findOrFail($orderId);
        }

        Order::query()->whereKey($orderId)->exists() ?: throw OrderNotFoundException::forId($orderId);

        throw InvalidOrderTransitionException::toStatus($orderId, OrderStatus::PartiallyRefunded);
    }
}
