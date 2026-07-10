<?php

namespace App\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent synchronously by App\Identity\Actions\RequestCustomerClaim through
 * Laravel's mailer contract, no queue dependency (stage-03 plan,
 * Non-goals: "Stage 3 sends its two verification emails ...
 * synchronously"). Lives under App\Identity\Mail rather than the
 * top-level App\Mail for the same reason
 * App\Identity\Mail\StaffInvitationMail already does: that preset also
 * requires every App\Mail class to implement ShouldQueue, which would
 * route a plain send() call through the queue instead.
 */
final class CustomerClaimMail extends Mailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Claim your Nodia account');
    }

    public function content(): Content
    {
        return new Content(htmlString: sprintf(
            '<p>Hi %s,</p><p>Use this claim code to set a password and finish setting up your account:</p><p>%s</p>',
            e($this->recipientName),
            e($this->token),
        ));
    }
}
