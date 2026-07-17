<?php

namespace App\Orders\Support;

use RuntimeException;

/**
 * Derives a per-event signing key from the application secret and the
 * event id via HKDF (stage-07 plan, "QR payload design"): no key
 * material is stored per ticket and nothing static leaks into the
 * database. The info string is versioned so Stage 9 can seed its first
 * stored per-event key from this exact derivation.
 */
final class DerivedTicketSigningKeyProvider implements TicketSigningKeyProvider
{
    public function keyForEvent(string $eventId): string
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        if ($appKey === '') {
            throw new RuntimeException('The application key is not set; ticket QR payloads cannot be signed.');
        }

        return hash_hkdf('sha256', $appKey, 32, 'nodia-ticket-qr-v1:'.$eventId);
    }
}
