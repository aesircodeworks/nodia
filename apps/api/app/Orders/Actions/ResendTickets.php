<?php

namespace App\Orders\Actions;

use App\Orders\Enums\TicketStatus;
use App\Orders\Exceptions\OrderNotFoundException;
use App\Orders\Exceptions\OrderNotPaidException;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;

/**
 * The resend-tickets seam (stage-07 plan, task breakdown item 14):
 * validates the order has issued tickets and returns them for the
 * dispatch pathway Stage 8a activates (SendOrderConfirmation and
 * GenerateTicketPdf consumers). Deliberately dormant until then: no
 * email goes out and the qr_rotation_counter stays untouched, so a
 * resend call cannot invalidate QR payloads the buyer already holds
 * while sending nothing in return. Stage 8a's resend activation task
 * owns the counter bump through the TicketQrCodec primitive.
 */
final class ResendTickets
{
    /**
     * @return list<Ticket>
     */
    public function __invoke(string $orderId): array
    {
        $order = Order::query()->find($orderId) ?? throw OrderNotFoundException::forId($orderId);

        $tickets = Ticket::query()
            ->where('order_id', $order->id)
            ->where('status', TicketStatus::Issued)
            ->get();

        if ($tickets->isEmpty()) {
            throw OrderNotPaidException::forId($orderId);
        }

        return $tickets->all();
    }
}
