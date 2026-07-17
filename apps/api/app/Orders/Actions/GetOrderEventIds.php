<?php

namespace App\Orders\Actions;

use App\Orders\Models\Order;
use Illuminate\Support\Collection;

/**
 * Bulk order ID to event ID lookup (stage-11 plan, task 8): neither
 * PaymentConfirmedPayload nor RefundCompletedPayload carries the event
 * an order belongs to, only order_id, so App\Payments\Actions
 * \GetPaymentEventFinanceFacts resolves it here rather than Payments
 * touching the orders table directly (event-conventions: consumers
 * needing more load it through the owning context's Actions). An order
 * ID with no matching order row is simply absent from the returned
 * collection; the caller decides how to treat a miss.
 */
final class GetOrderEventIds
{
    /**
     * @param  list<string>  $orderIds
     * @return Collection<string, string> event_id keyed by order_id
     */
    public function __invoke(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return Order::query()->whereIn('id', $orderIds)->pluck('event_id', 'id');
    }
}
