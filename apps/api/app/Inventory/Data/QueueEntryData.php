<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * POST /v1/storefront/events/{event}/queue-entries and GET
 * /v1/storefront/queue-entries/{entry} response shape (stage-10 plan,
 * Endpoints). id is the entrant's own capability (section 14.4):
 * possessing it is what the poll endpoint accepts as proof of ownership,
 * nothing else is checked. position is null once admitted; admission_
 * token and admission_expires_at stay null until the gatekeeper
 * (stage-10 plan task breakdown item 8, not built by this task) admits
 * the entrant, at which point they are signed at response time from the
 * admitted Redis state rather than ever being persisted (stage-10 plan,
 * Endpoints: "the token is signed at response time from the admitted
 * state, so nothing secret rests in Redis").
 */
#[MapName(SnakeCaseMapper::class)]
class QueueEntryData extends Data
{
    public function __construct(
        public string $id,
        public string $eventId,
        public string $status,
        public ?int $position,
        public ?string $admissionToken,
        public ?string $admissionExpiresAt,
    ) {}
}
