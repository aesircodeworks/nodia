<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Models\TicketType;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * The narrow read model App\EventCatalog\Actions\ResolveEventForHold
 * hands to Inventory's App\Inventory\Actions\CreateHold (stage-06 plan,
 * Endpoints "POST /v1/storefront/holds"): Inventory never touches
 * App\EventCatalog\Models\TicketType directly (system-design 3.1
 * boundary rule, tests/Architecture/ContextBoundariesTest.php). Hidden
 * from TypeScript generation: this is an internal cross-context read
 * model, not a wire contract. maxPerCustomer is stage-10's additive
 * per-ticket-type purchase limit (Data model
 * "ticket_types.max_per_customer"): this is the only path Inventory may
 * read it through; null means unlimited.
 */
#[Hidden]
class HoldableTicketTypeData extends Data
{
    public function __construct(
        public string $id,
        public bool $requiresSeat,
        public ?CarbonInterface $salesStart,
        public ?CarbonInterface $salesEnd,
        public ?int $maxPerCustomer,
    ) {}

    public static function fromModel(TicketType $ticketType): self
    {
        return new self(
            $ticketType->id,
            $ticketType->requires_seat,
            $ticketType->sales_start,
            $ticketType->sales_end,
            $ticketType->max_per_customer,
        );
    }
}
