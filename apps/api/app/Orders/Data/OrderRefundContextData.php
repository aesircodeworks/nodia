<?php

namespace App\Orders\Data;

use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\TicketStatus;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;

/**
 * The internal order facts Payments needs to create a refund
 * (stage-08b plan, Endpoints): the refundability status check and the
 * issued-ticket set validating the voiding selection. Never serialized
 * to the wire.
 */
final class OrderRefundContextData
{
    /**
     * @param  list<string>  $issuedTicketIds
     */
    public function __construct(
        public readonly string $id,
        public readonly OrderStatus $status,
        public readonly array $issuedTicketIds,
    ) {}

    public static function fromModel(Order $order): self
    {
        return new self(
            $order->id,
            $order->status,
            Ticket::query()
                ->where('order_id', $order->id)
                ->where('status', TicketStatus::Issued)
                ->pluck('id')
                ->all(),
        );
    }
}
