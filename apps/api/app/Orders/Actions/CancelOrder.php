<?php

namespace App\Orders\Actions;

use App\Inventory\Actions\ReleaseHold;
use App\Orders\Actions\Concerns\TransitionsOrderStatus;
use App\Orders\Data\OrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Exceptions\OrderNotCancelableException;
use RuntimeException;

/**
 * pending to canceled: buyer abandons (system-design 7.1). Releases the
 * hold in the same transaction so availability arithmetic recovers
 * exactly; ReleaseHold is a no-op when the sweeper already expired it,
 * which is what makes the HoldExpired consumer's reuse of this arc
 * harmless.
 */
final class CancelOrder
{
    use TransitionsOrderStatus;

    public function __construct(private readonly ReleaseHold $releaseHold) {}

    public function __invoke(string $orderId): OrderData
    {
        $order = $this->transition($orderId, OrderStatus::Pending, OrderStatus::Canceled);

        ($this->releaseHold)($order->hold_id);

        return OrderData::fromModel($order->load('items'));
    }

    private function transitionRefused(string $orderId, OrderStatus $to): RuntimeException
    {
        return OrderNotCancelableException::forId($orderId);
    }
}
