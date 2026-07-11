<?php

namespace App\Inventory\Events;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * One entry of HoldCreatedPayload's items list (stage-06 plan, Domain
 * events: "items as [{ticket_type_id, quantity}]"). Hidden from
 * TypeScript generation: event payloads are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class HoldCreatedItemPayload extends Data
{
    public function __construct(
        public string $ticketTypeId,
        public int $quantity,
    ) {}
}
