<?php

namespace App\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent synchronously by App\Identity\Actions\RequestPasswordReset through
 * Laravel's mailer contract, no queue dependency (stage-03 plan,
 * Non-goals: "Stage 3 sends its ... verification emails ...
 * synchronously"). Lives under App\Identity\Mail rather than the
 * top-level App\Mail for the same reason App\Identity\Mail\StaffInvitationMail
 * and App\Identity\Mail\CustomerClaimMail already do: that namespace also
 * requires every class in it to implement ShouldQueue, which would route
 * a plain send() call through the queue instead.
 */
final class PasswordResetMail extends Mailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Nodia password');
    }

    public function content(): Content
    {
        return new Content(htmlString: sprintf(
            '<p>Hi %s,</p><p>Use this code to reset your password:</p><p>%s</p>',
            e($this->recipientName),
            e($this->token),
        ));
    }
}
