<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Models\TicketType;
use App\Support\Money\Money;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal read model App\EventCatalog\Actions\ResolveTicketTypePricing
 * returns to Orders for pricing an order at hold conversion (stage-07
 * plan, Slice 1), never part of the wire contract.
 */
#[Hidden]
class PricedTicketTypeData extends Data
{
    public function __construct(
        public string $id,
        public Money $price,
        public bool $requiresSeat,
    ) {}

    public static function fromModel(TicketType $ticketType): self
    {
        return new self($ticketType->id, $ticketType->price, $ticketType->requires_seat);
    }
}
