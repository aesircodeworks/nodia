<?php

namespace App\Support\Outbox;

use Carbon\CarbonImmutable;

/**
 * The outbox envelope assembled at record time (event-conventions), before
 * the append-only outbox_events insert. sequence is assigned by the
 * database identity column and is not part of this object.
 *
 * @phpstan-type PayloadArray array<string, mixed>
 */
final readonly class OutboxEnvelope
{
    /**
     * @param  PayloadArray  $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $tenantId,
        public string $aggregateType,
        public string $aggregateId,
        public string $correlationId,
        public CarbonImmutable $occurredAt,
        public array $payload,
    ) {}
}
