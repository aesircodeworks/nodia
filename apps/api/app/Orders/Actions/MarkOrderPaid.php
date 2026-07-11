<?php

namespace App\Orders\Actions;

use App\Orders\Actions\Concerns\TransitionsOrderStatus;
use App\Orders\Enums\OrderStatus;

/**
 * awaiting_payment to paid: gateway confirms (system-design 7.1). This
 * task ships the transition guard only; the paid path task in this
 * stage adds CommitHold, ticket issuance, and TicketIssued recording in
 * this same transaction (stage-07 plan, Slice 3).
 */
final class MarkOrderPaid
{
    use TransitionsOrderStatus;

    public function __invoke(string $orderId): void
    {
        $this->transition($orderId, OrderStatus::AwaitingPayment, OrderStatus::Paid);
    }
}
