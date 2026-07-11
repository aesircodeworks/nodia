<?php

namespace App\Orders\Actions;

use App\Orders\Data\OrderPaymentContextData;
use App\Orders\Models\Order;

/**
 * A cross-context read for the Payments surface (stage-08a plan,
 * Endpoints): Payments never touches the orders table directly (section
 * 3.1 boundary rule). Scoped to the owning customer exactly like the
 * buyer order endpoints; a missing, cross-tenant (via RLS), or
 * foreign-customer order returns null and the caller renders the same
 * problem for all three, so existence never leaks.
 */
final class ResolveOrderForPayment
{
    public function __invoke(string $orderId, string $customerId): ?OrderPaymentContextData
    {
        $order = Order::query()
            ->whereKey($orderId)
            ->where('customer_id', $customerId)
            ->first();

        return $order === null ? null : OrderPaymentContextData::fromModel($order);
    }
}
