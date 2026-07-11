<?php

namespace App\Orders\Jobs;

use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;

/**
 * The Orders subscriber for Inventory's HoldExpired (stage-07 plan,
 * Domain events "Consumed"): a pending order whose hold the Stage 6
 * sweeper released maps to buyer abandonment, the only 7.1 arc out of
 * pending besides awaiting_payment. One conditional UPDATE; zero
 * affected rows (no order, order already advanced, order already
 * terminal) is success, which is exactly what makes duplicate delivery
 * harmless. The hold's inventory was already released by the sweeper,
 * so no Inventory call happens here. Orders on awaiting_payment are
 * untouched: their holds are extended per system-design 7.4 and Stage
 * 8a's payment expiry owns that path. Delivery progress and tenant
 * context are ProcessOutboxDelivery's job (event-conventions).
 */
final readonly class CancelOrderOnHoldExpired implements OutboxSubscriber
{
    public const string NAME = 'cancel_order_on_hold_expired';

    public function handle(OutboxEvent $event): void
    {
        Order::query()
            ->where('hold_id', $event->aggregate_id)
            ->where('status', OrderStatus::Pending)
            ->update(['status' => OrderStatus::Canceled]);
    }
}
