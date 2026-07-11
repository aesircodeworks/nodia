<?php

namespace App\Orders\Actions;

use App\Inventory\Actions\CommitHold;
use App\Inventory\Data\CommitHoldData;
use App\Orders\Actions\Concerns\TransitionsOrderStatus;
use App\Orders\Enums\OrderStatus;

/**
 * awaiting_payment to paid: gateway confirms (system-design 7.1). The
 * conditional UPDATE wins exactly once; in that same transaction the
 * hold commits from held to sold through Inventory's CommitHold and
 * tickets are issued exactly once with one TicketIssued per ticket
 * (stage-07 plan, Slice 3). Stage 8a's payment confirmation is the
 * production caller.
 */
final class MarkOrderPaid
{
    use TransitionsOrderStatus;

    public function __construct(
        private readonly CommitHold $commitHold,
        private readonly IssueTickets $issueTickets,
    ) {}

    public function __invoke(string $orderId): void
    {
        $order = $this->transition($orderId, OrderStatus::AwaitingPayment, OrderStatus::Paid);

        ($this->commitHold)(new CommitHoldData($order->hold_id));

        ($this->issueTickets)($order->load('items'));
    }
}
