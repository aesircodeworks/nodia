<?php

namespace App\Orders\Actions;

use App\Identity\Actions\ResolveCustomerContact;
use App\Orders\Enums\TicketStatus;
use App\Orders\Exceptions\OrderNotFoundException;
use App\Orders\Exceptions\OrderNotPaidException;
use App\Orders\Mail\OrderConfirmationMail;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The staff resend pathway, activated by Stage 8a now that the email
 * consumer exists (stage-07 plan, task breakdown item 14; stage-08a
 * plan, task breakdown item 12). Every issued ticket's
 * qr_rotation_counter bumps before the resend, so every previously
 * rendered QR payload stops verifying under the TicketQrCodec primitive
 * while fresh renders verify; the confirmation email then goes out
 * again through the same mailable the paid path sends.
 */
final class ResendTickets
{
    public function __construct(
        private readonly ResolveCustomerContact $resolveContact,
    ) {}

    /**
     * @return list<Ticket>
     */
    public function __invoke(string $orderId): array
    {
        $order = Order::query()->find($orderId) ?? throw OrderNotFoundException::forId($orderId);

        $bumped = DB::table('tickets')
            ->where('order_id', $order->id)
            ->where('status', TicketStatus::Issued->value)
            ->update([
                'qr_rotation_counter' => DB::raw('qr_rotation_counter + 1'),
                'updated_at' => Date::now(),
            ]);

        if ($bumped === 0) {
            throw OrderNotPaidException::forId($orderId);
        }

        $tickets = Ticket::query()
            ->where('order_id', $order->id)
            ->where('status', TicketStatus::Issued)
            ->get();

        $contact = ($this->resolveContact)($order->customer_id);

        if ($contact !== null) {
            Mail::to($contact->email)
                ->locale($contact->locale ?? config()->string('app.locale'))
                ->send(new OrderConfirmationMail($contact->name, $tickets->count(), $order->total));
        }

        return $tickets->all();
    }
}
