<?php

namespace App\Orders\Support;

/**
 * The result of TicketQrCodec::verify (stage-07 plan, "QR payload
 * design"). Internal to the API: Stage 9's check-in surface builds its
 * wire responses on top of this, it is never serialized itself.
 */
final readonly class TicketQrVerification
{
    private function __construct(
        public bool $valid,
        public ?string $ticketId,
    ) {}

    public static function verified(string $ticketId): self
    {
        return new self(true, $ticketId);
    }

    public static function rejected(): self
    {
        return new self(false, null);
    }
}
