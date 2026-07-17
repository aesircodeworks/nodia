<?php

namespace App\Orders\Support;

use App\Orders\Actions\GetOrCreateActiveSigningKey;

/**
 * Stage 9's swap of the Stage 7 seam: signs and verifies through the
 * event's stored active key instead of a fresh HKDF derivation on every
 * call (stage-09 plan, "Provider-swap continuity"). Get-or-create seeds
 * an event's first key from the exact Stage 7 derivation, so this class
 * is a drop-in replacement for DerivedTicketSigningKeyProvider: neither
 * TicketQrCodec nor the payload format changes, and every payload
 * rendered before the swap still verifies against the seeded version 1
 * key.
 */
final class EventSigningKeyProvider implements TicketSigningKeyProvider
{
    public function __construct(private readonly GetOrCreateActiveSigningKey $getOrCreateActive) {}

    public function keyForEvent(string $eventId): string
    {
        return ($this->getOrCreateActive)($eventId)->secret;
    }
}
