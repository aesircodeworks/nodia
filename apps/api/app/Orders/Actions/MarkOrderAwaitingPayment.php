<?php

namespace App\Orders\Actions;

use App\Orders\Actions\Concerns\TransitionsOrderStatus;
use App\Orders\Enums\OrderStatus;

/**
 * pending to awaiting_payment: payment initiated (system-design 7.1).
 * No HTTP surface in this stage; Stage 8a's payment initiation is the
 * first caller. The hold stays held and is extended to the payment
 * window by Stage 8a (system-design 7.4).
 */
final class MarkOrderAwaitingPayment
{
    use TransitionsOrderStatus;

    public function __invoke(string $orderId): void
    {
        $this->transition($orderId, OrderStatus::Pending, OrderStatus::AwaitingPayment);
    }
}
