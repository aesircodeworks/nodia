<?php

namespace App\Orders\Support;

/**
 * The seam between the QR codec and key material (stage-07 plan, "QR
 * payload design"). Stage 7 binds a derived-key implementation; Stage 9
 * swaps in stored, rotating per-event keys (system-design 11) behind
 * this same interface without touching the codec or payload format,
 * seeding each event's first stored key from the Stage 7 derivation so
 * payloads rendered before the swap keep verifying.
 */
interface TicketSigningKeyProvider
{
    public function keyForEvent(string $eventId): string;
}
