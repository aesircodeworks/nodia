<?php

namespace App\Orders\Jobs;

use App\Identity\Actions\ResolveCustomerContact;
use App\Orders\Mail\OrderConfirmationMail;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The Orders subscriber for TicketIssued that sends one confirmation
 * email per order (stage-08a plan, Slice 9). TicketIssued is recorded
 * per ticket, so idempotence across distinct events for one order comes
 * from the confirmation_sent_at claim: a conditional UPDATE checked by
 * affected-row count, and only the claim winner sends. The claim and
 * the send share ProcessOutboxDelivery's tenant transaction, so a
 * mailer failure rolls the claim back and the delivery retries.
 */
final readonly class SendOrderConfirmation implements OutboxSubscriber
{
    public const string NAME = 'send_order_confirmation';

    public function __construct(
        private ResolveCustomerContact $resolveContact,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $orderId = (string) $event->payload['order_id'];

        $order = Order::query()->find($orderId);

        if ($order === null || $order->confirmation_sent_at !== null) {
            return;
        }

        // Contact resolves before the claim so a missing customer (an
        // anonymization race, a deleted row) leaves confirmation_sent_at
        // null and a later delivery can still send once the contact
        // exists, instead of the claim permanently suppressing the email.
        $contact = ($this->resolveContact)($order->customer_id);

        if ($contact === null) {
            Log::critical('orders.confirmation_contact_missing', [
                'order_id' => $orderId,
                'customer_id' => $order->customer_id,
                'tenant_id' => $event->tenant_id,
            ]);

            return;
        }

        $claimed = DB::table('orders')
            ->where('id', $orderId)
            ->whereNull('confirmation_sent_at')
            ->update(['confirmation_sent_at' => Date::now(), 'updated_at' => Date::now()]);

        if ($claimed !== 1) {
            return;
        }

        $ticketCount = Ticket::query()->where('order_id', $order->id)->count();

        Mail::to($contact->email)
            ->locale($contact->locale ?? config()->string('app.locale'))
            ->send(new OrderConfirmationMail($contact->name, $ticketCount, $order->total));
    }
}
