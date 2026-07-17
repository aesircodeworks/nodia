<?php

namespace App\Orders\Jobs;

use App\Identity\Actions\ResolveCustomerContact;
use App\Identity\Data\CustomerContactData;
use App\Orders\Mail\OrderConfirmationMail;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Support\Outbox\DetachedOutboxSubscriber;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The Orders subscriber for TicketIssued that sends one confirmation
 * email per order (stage-08a plan, Slice 9). TicketIssued is recorded
 * per ticket, so idempotence across distinct events for one order comes
 * from the confirmation_sent_at claim: a conditional UPDATE checked by
 * affected-row count, and only the claim winner sends. Detached from
 * the delivery transaction so the mailer round trip never holds a
 * connection or row lock: the claim commits in a short tenant
 * transaction, the send runs outside any transaction, and a send
 * failure reverses the claim before rethrowing so the delivery retries
 * (5 attempts, 1-minute backoff, config/outbox.php).
 */
final readonly class SendOrderConfirmation implements DetachedOutboxSubscriber
{
    public const string NAME = 'send_order_confirmation';

    public function __construct(
        private TenantTransaction $transactions,
        private ResolveCustomerContact $resolveContact,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $orderId = (string) $event->payload['order_id'];

        [$contact, $order, $ticketCount] = $this->transactions->asTenant(
            $event->tenant_id,
            function () use ($orderId, $event): array {
                $order = Order::query()->find($orderId);

                if ($order === null || $order->confirmation_sent_at !== null) {
                    return [null, null, 0];
                }

                // Contact resolves before the claim so a missing customer
                // (an anonymization race, a deleted row) leaves
                // confirmation_sent_at null. Throwing (rather than a quiet
                // no-op) keeps the delivery unprocessed so the retry
                // policy re-attempts; without it every TicketIssued
                // delivery for the order would be marked processed and no
                // later attempt would ever run.
                $contact = ($this->resolveContact)($order->customer_id);

                if ($contact === null) {
                    Log::critical('orders.confirmation_contact_missing', [
                        'order_id' => $orderId,
                        'customer_id' => $order->customer_id,
                        'tenant_id' => $event->tenant_id,
                    ]);

                    throw new \RuntimeException(sprintf(
                        'orders: no customer contact for order "%s"; confirmation delivery left unprocessed for retry.',
                        $orderId,
                    ));
                }

                $claimed = DB::table('orders')
                    ->where('id', $orderId)
                    ->whereNull('confirmation_sent_at')
                    ->update(['confirmation_sent_at' => Date::now(), 'updated_at' => Date::now()]);

                if ($claimed !== 1) {
                    return [null, null, 0];
                }

                return [$contact, $order, Ticket::query()->where('order_id', $order->id)->count()];
            },
        );

        if ($contact === null || $order === null) {
            return;
        }

        try {
            $this->send($contact, $ticketCount, $order);
        } catch (Throwable $sendFailure) {
            // The claim already committed; release it so the delivery
            // retry (or the sweeper) can send again, otherwise the order
            // reads as confirmed with no mail ever accepted.
            $this->transactions->asTenant($event->tenant_id, fn (): int => DB::table('orders')
                ->where('id', $orderId)
                ->update(['confirmation_sent_at' => null, 'updated_at' => Date::now()]));

            throw $sendFailure;
        }
    }

    private function send(CustomerContactData $contact, int $ticketCount, Order $order): void
    {
        Mail::to($contact->email)
            ->locale($contact->locale ?? config()->string('app.locale'))
            ->send(new OrderConfirmationMail($contact->name, $ticketCount, $order->total));
    }
}
