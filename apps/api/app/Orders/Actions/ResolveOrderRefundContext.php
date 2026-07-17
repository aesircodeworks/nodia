<?php

namespace App\Orders\Actions;

use App\Orders\Data\OrderRefundContextData;
use App\Orders\Models\Order;

/**
 * A cross-context read for the refund surface (stage-08b plan,
 * Endpoints): Payments never touches the orders or tickets tables
 * directly (section 3.1 boundary rule), mirroring
 * ResolveOrderForPayment. A missing or cross-tenant (via RLS) order
 * returns null.
 */
final class ResolveOrderRefundContext
{
    public function __invoke(string $orderId): ?OrderRefundContextData
    {
        $order = Order::query()->whereKey($orderId)->first();

        return $order === null ? null : OrderRefundContextData::fromModel($order);
    }
}
