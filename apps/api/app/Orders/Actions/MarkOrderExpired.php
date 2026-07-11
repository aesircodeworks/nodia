<?php

namespace App\Orders\Actions;

use App\Inventory\Actions\ReleaseHold;
use App\Orders\Actions\Concerns\TransitionsOrderStatus;
use App\Orders\Enums\OrderStatus;

/**
 * awaiting_payment to expired: payment window elapsed (system-design
 * 7.1). Releases the hold in the same transaction; ReleaseHold is a
 * no-op when the sweeper already expired it.
 */
final class MarkOrderExpired
{
    use TransitionsOrderStatus;

    public function __construct(private readonly ReleaseHold $releaseHold) {}

    public function __invoke(string $orderId): void
    {
        $order = $this->transition($orderId, OrderStatus::AwaitingPayment, OrderStatus::Expired);

        ($this->releaseHold)($order->hold_id);
    }
}
