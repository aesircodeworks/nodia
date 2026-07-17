<?php

namespace App\Identity\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for CustomerAnonymized (stage-12 plan, Domain
 * events). Identifiers only, deliberately no name or email: outbox rows
 * are retained and replayed (system-design 9.1), so carrying the erased
 * PII here would defeat the erasure it announces. data_subject_request_id
 * lets a consumer correlate the scrub back to the request that triggered
 * it without a second lookup. Hidden from TypeScript generation: event
 * payloads are not API contracts, mirroring CustomerRegisteredPayload's
 * own precedent.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class CustomerAnonymizedPayload extends Data
{
    public function __construct(
        public string $customerId,
        public string $dataSubjectRequestId,
    ) {}
}
