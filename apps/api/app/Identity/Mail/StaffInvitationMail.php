<?php

namespace App\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent synchronously by App\Identity\Actions\InviteUser through Laravel's
 * mailer contract, no queue dependency (stage-03 plan, Non-goals:
 * "Transactional email through the outbox and Resend consumers: Stage
 * 8a. Stage 3 sends its two verification emails ... synchronously"). Lives
 * under App\Identity\Mail rather than the top-level App\Mail the Pest
 * Laravel arch preset expects: that preset also requires every App\Mail
 * class to implement ShouldQueue (Mailer::sendMailable() then routes a
 * plain send() call through the queue instead, the opposite of what this
 * task needs), so this class is added to tests/Architecture/PresetTest.php's
 * ignoring() list instead, the same treatment Identity's own
 * Http\Controllers and Models already get there.
 */
final class StaffInvitationMail extends Mailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to Nodia');
    }

    public function content(): Content
    {
        return new Content(htmlString: sprintf(
            '<p>Hi %s,</p><p>You have been invited to join a Nodia tenant. Use this acceptance code to set your password:</p><p>%s</p>',
            e($this->recipientName),
            e($this->token),
        ));
    }
}
