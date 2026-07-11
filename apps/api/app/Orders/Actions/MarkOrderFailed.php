<?php

namespace App\Orders\Actions;

use App\Inventory\Actions\ReleaseHold;
use App\Orders\Actions\Concerns\TransitionsOrderStatus;
use App\Orders\Enums\OrderStatus;

/**
 * awaiting_payment to failed: gateway declines (system-design 7.1).
 * Releases the hold in the same transaction. How Stage 8a maps
 * individual payment-attempt declines onto this terminal arc is Stage
 * 8a's design question (stage-07 plan, Risks).
 */
final class MarkOrderFailed
{
    use TransitionsOrderStatus;

    public function __construct(private readonly ReleaseHold $releaseHold) {}

    public function __invoke(string $orderId): void
    {
        $order = $this->transition($orderId, OrderStatus::AwaitingPayment, OrderStatus::Failed);

        ($this->releaseHold)($order->hold_id);
    }
}
