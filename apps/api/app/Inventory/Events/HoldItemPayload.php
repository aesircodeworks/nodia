<?php

namespace App\Inventory\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * One entry of a hold event payload's items list (stage-06 plan, Domain
 * events: "items as [{ticket_type_id, quantity}]"), shared by
 * HoldCreatedPayload, HoldReleasedPayload, and HoldExpiredPayload since
 * all three carry the same per-item shape. Hidden from TypeScript
 * generation: event payloads are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class HoldItemPayload extends Data
{
    public function __construct(
        public string $ticketTypeId,
        public int $quantity,
    ) {}
}
