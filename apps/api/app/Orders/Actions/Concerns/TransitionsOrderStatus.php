<?php

namespace App\Orders\Actions\Concerns;

use App\Orders\Enums\OrderStatus;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Orders\Exceptions\OrderNotFoundException;
use App\Orders\Models\Order;

/**
 * The one way an order status ever changes: a single conditional UPDATE
 * guarded on the from-status and checked by affected-row count
 * (system-design 7.1, data-conventions, master plan test-first rule 2).
 * Zero rows on an existing order raises the caller-supplied exception;
 * a missing order is order_not_found either way.
 */
trait TransitionsOrderStatus
{
    private function transition(string $orderId, OrderStatus $from, OrderStatus $to): Order
    {
        $affected = Order::query()
            ->whereKey($orderId)
            ->where('status', $from)
            ->update(['status' => $to]);

        if ($affected === 1) {
            return Order::query()->findOrFail($orderId);
        }

        Order::query()->whereKey($orderId)->exists() ?: throw OrderNotFoundException::forId($orderId);

        throw $this->transitionRefused($orderId, $to);
    }

    private function transitionRefused(string $orderId, OrderStatus $to): \RuntimeException
    {
        return InvalidOrderTransitionException::toStatus($orderId, $to);
    }
}
