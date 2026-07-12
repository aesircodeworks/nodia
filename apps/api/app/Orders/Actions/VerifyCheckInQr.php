<?php

namespace App\Orders\Actions;

use App\Orders\Data\QrVerificationResultData;
use App\Orders\Data\VerifyCheckInQrData;
use App\Orders\Enums\QrVerificationOutcome;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Models\EventSigningKey;
use App\Orders\Support\TicketCheckInStatusMapper;
use Illuminate\Support\Facades\DB;

/**
 * Verifies a scanned QR payload against an event's stored signing keys
 * and the ticket it names, returning a typed result rather than a bare
 * bool (stage-09 plan, Task 8: "Orders read Actions for CheckIn"). Task
 * 10's RecordScan is the only planned caller; it maps the outcome to the
 * stable problem-document codes in the Endpoints table.
 *
 * Distinct from TicketQrCodec::verify (stage-07), which checks only the
 * event's single active key through TicketSigningKeyProvider: this
 * Action trials every one of the event's keys directly against
 * event_signing_keys so a device holding a QR rendered under a since-
 * retired key still verifies, and so a signature that only matches a
 * revoked key is classified separately from one that matches nothing at
 * all (stage-09 plan, Task 5 deviation note). Keys are tried newest
 * version first within each group: active and retired keys (the ones
 * that still verify) before revoked keys, which are tried only to tell
 * qr_key_revoked apart from qr_signature_invalid.
 */
final class VerifyCheckInQr
{
    public function __invoke(VerifyCheckInQrData $data): QrVerificationResultData
    {
        $decoded = $this->decode($data->qrPayload);

        if ($decoded === null) {
            return QrVerificationResultData::outcome(QrVerificationOutcome::SignatureInvalid);
        }

        [$ticketId, $eventId, $rotation, $signature] = $decoded;

        $keys = EventSigningKey::query()
            ->where('event_id', $eventId)
            ->orderByDesc('key_version')
            ->get();

        $expected = fn (EventSigningKey $key): string => hash_hmac(
            'sha256',
            $ticketId.'|'.$eventId.'|'.$rotation,
            $key->secret,
        );

        $verifiedByNonRevoked = $keys
            ->filter(fn (EventSigningKey $key): bool => $key->status !== SigningKeyStatus::Revoked)
            ->first(fn (EventSigningKey $key): bool => hash_equals($expected($key), $signature));

        if ($verifiedByNonRevoked === null) {
            $verifiedByRevoked = $keys
                ->filter(fn (EventSigningKey $key): bool => $key->status === SigningKeyStatus::Revoked)
                ->first(fn (EventSigningKey $key): bool => hash_equals($expected($key), $signature));

            return QrVerificationResultData::outcome(
                $verifiedByRevoked !== null ? QrVerificationOutcome::KeyRevoked : QrVerificationOutcome::SignatureInvalid,
            );
        }

        $ticket = DB::table('tickets')
            ->where('id', $ticketId)
            ->where('event_id', $eventId)
            ->first(['id', 'status', 'qr_rotation_counter']);

        if ($ticket === null) {
            return QrVerificationResultData::outcome(QrVerificationOutcome::TicketNotFound);
        }

        if ((int) $ticket->qr_rotation_counter !== $rotation) {
            return QrVerificationResultData::forTicket(
                QrVerificationOutcome::RotationStale,
                $ticketId,
                $eventId,
                (int) $ticket->qr_rotation_counter,
            );
        }

        return QrVerificationResultData::forTicket(
            TicketCheckInStatusMapper::classify($ticket->status),
            $ticketId,
            $eventId,
            $rotation,
        );
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: string}|null
     */
    private function decode(string $payload): ?array
    {
        $decoded = json_decode($this->base64UrlDecode($payload) ?? '', true);

        if (! is_array($decoded)) {
            return null;
        }

        $ticketId = $decoded['ticket_id'] ?? null;
        $eventId = $decoded['event_id'] ?? null;
        $rotation = $decoded['rotation'] ?? null;
        $signature = $decoded['signature'] ?? null;

        if (! is_string($ticketId) || ! is_string($eventId) || ! is_int($rotation) || ! is_string($signature)) {
            return null;
        }

        return [$ticketId, $eventId, $rotation, $signature];
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
