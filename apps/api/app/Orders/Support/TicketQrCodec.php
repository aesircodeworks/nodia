<?php

namespace App\Orders\Support;

use App\Orders\Models\Ticket;

/**
 * Signs and verifies rotatable ticket QR payloads (stage-07 plan, "QR
 * payload design"; system-design 8.3 notes, 14.4). The payload is
 * base64url JSON of ticket_id, event_id, the per-ticket rotation
 * counter, and an HMAC-SHA256 signature over those three fields under
 * the event's key; it is computed on render and never stored.
 * Verification checks the signature, then that the embedded counter
 * equals the ticket's current qr_rotation_counter, so bumping the
 * counter invalidates every previously rendered payload.
 */
final class TicketQrCodec
{
    public function __construct(private readonly TicketSigningKeyProvider $keys) {}

    public function sign(Ticket $ticket): string
    {
        $body = [
            'ticket_id' => $ticket->id,
            'event_id' => $ticket->event_id,
            'rotation' => $ticket->qr_rotation_counter,
        ];

        $body['signature'] = $this->signature($ticket->id, $ticket->event_id, $ticket->qr_rotation_counter);

        return $this->base64UrlEncode((string) json_encode($body));
    }

    public function verify(string $payload): TicketQrVerification
    {
        $decoded = json_decode($this->base64UrlDecode($payload) ?? '', true);

        if (! is_array($decoded)) {
            return TicketQrVerification::rejected();
        }

        $ticketId = $decoded['ticket_id'] ?? null;
        $eventId = $decoded['event_id'] ?? null;
        $rotation = $decoded['rotation'] ?? null;
        $signature = $decoded['signature'] ?? null;

        if (! is_string($ticketId) || ! is_string($eventId) || ! is_int($rotation) || ! is_string($signature)) {
            return TicketQrVerification::rejected();
        }

        if (! hash_equals($this->signature($ticketId, $eventId, $rotation), $signature)) {
            return TicketQrVerification::rejected();
        }

        $ticket = Ticket::query()->find($ticketId);

        if ($ticket === null || $ticket->event_id !== $eventId || $ticket->qr_rotation_counter !== $rotation) {
            return TicketQrVerification::rejected();
        }

        return TicketQrVerification::verified($ticket->id);
    }

    private function signature(string $ticketId, string $eventId, int $rotation): string
    {
        return hash_hmac(
            'sha256',
            $ticketId.'|'.$eventId.'|'.$rotation,
            $this->keys->keyForEvent($eventId),
        );
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
