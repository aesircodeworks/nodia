<?php

namespace App\Orders\Actions;

use App\Orders\Data\OrderPaymentContextData;
use App\Orders\Exceptions\OrderNotFoundException;
use App\Orders\Models\Order;

/**
 * Re-reads the order under SELECT ... FOR UPDATE so Payments can claim
 * it before any gateway call. The row lock is held for the remainder of
 * the request transaction, which serializes concurrent initiations with
 * different Idempotency-Keys: only the lock winner reaches the gateway,
 * and a loser wakes to the winner's committed status instead of a stale
 * pending snapshot, so the gateway is never asked to authorize the same
 * order twice.
 */
final class LockOrderForPayment
{
    public function __invoke(string $orderId): OrderPaymentContextData
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first()
            ?? throw OrderNotFoundException::forId($orderId);

        return OrderPaymentContextData::fromModel($order);
    }
}
