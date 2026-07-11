<?php

namespace App\Orders\Mail;

use App\Support\Money\Money;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent synchronously by App\Orders\Jobs\SendOrderConfirmation through
 * Laravel's mailer contract (ADR 010 keeps the provider swappable, so
 * Resend is a transport concern, never referenced here). Lives under
 * App\Orders\Mail rather than the top-level App\Mail for the same
 * reason as Identity's mailables: the architecture preset requires
 * every App\Mail class to implement ShouldQueue. Carries no raw ids and
 * no QR payload: QR payloads are generated on render, never embedded in
 * durable artifacts (system-design 8.3).
 */
final class OrderConfirmationMail extends Mailable
{
    public function __construct(
        public readonly ?string $recipientName,
        public readonly int $ticketCount,
        public readonly Money $total,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your order is confirmed');
    }

    public function content(): Content
    {
        return new Content(htmlString: sprintf(
            '<p>Hi %s,</p><p>Your payment was confirmed and your %d ticket%s %s ready.</p><p>Total charged: %s %s.</p><p>Your tickets are available in your account and attached to this order.</p>',
            e($this->recipientName ?? 'there'),
            $this->ticketCount,
            $this->ticketCount === 1 ? '' : 's',
            $this->ticketCount === 1 ? 'is' : 'are',
            e($this->total->currency),
            $this->formattedTotal(),
        ));
    }

    private function formattedTotal(): string
    {
        return number_format(intdiv($this->total->amount, 100)).'.'.sprintf('%02d', $this->total->amount % 100);
    }
}
