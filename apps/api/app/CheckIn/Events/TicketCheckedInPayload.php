<?php

namespace App\CheckIn\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for TicketCheckedIn (stage-09 plan, Domain
 * events "Produced"; system-design 9.3 group 6). Recorded exactly once
 * per ticket, in the same transaction as the accepted check_ins insert;
 * a later reconciliation swap does not re-record it.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class TicketCheckedInPayload extends Data
{
    public function __construct(
        public string $checkInId,
        public string $ticketId,
        public string $eventId,
        public string $deviceId,
        public string $userId,
        public string $scannedAt,
    ) {}
}
