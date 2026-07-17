<?php

namespace App\Orders\Data;

use App\Orders\Enums\QrVerificationOutcome;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Output of the VerifyCheckInQr Action (stage-09 plan, Task 8: "Orders
 * read Actions for CheckIn"). ticketId and eventId are populated once
 * the payload's signature has verified against a non-revoked key (every
 * outcome from RotationStale onward); they stay null for SignatureInvalid
 * and KeyRevoked, since a payload that never verified carries no
 * trustworthy identity. Task 10's RecordScan maps outcome to the stable
 * problem-document codes in the Endpoints table.
 */
#[MapName(SnakeCaseMapper::class)]
class QrVerificationResultData extends Data
{
    public function __construct(
        public QrVerificationOutcome $outcome,
        public ?string $ticketId,
        public ?string $eventId,
        public ?int $rotationCounter,
    ) {}

    public static function outcome(QrVerificationOutcome $outcome): self
    {
        return new self($outcome, null, null, null);
    }

    public static function forTicket(
        QrVerificationOutcome $outcome,
        string $ticketId,
        string $eventId,
        int $rotationCounter,
    ): self {
        return new self($outcome, $ticketId, $eventId, $rotationCounter);
    }
}
