<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * The narrow read model App\EventCatalog\Actions\ResolveEventForHold
 * returns for a published event (stage-06 plan, Endpoints "POST
 * /v1/storefront/holds"). Hidden from TypeScript generation, mirroring
 * App\EventCatalog\Data\HoldableTicketTypeData's own precedent.
 *
 * onSalePolicy is stage-10's additive per-event high-demand
 * configuration (Data model "events.on_sale_policy"): App\Inventory\
 * Actions\JoinQueue reads highDemand and challengeRequired through this
 * same field, the only path Inventory may read on_sale_policy through
 * (system-design 3.1 boundary rule), mirroring maxPerCustomer's own
 * addition to HoldableTicketTypeData in stage-10 task 3.
 */
#[Hidden]
class HoldableEventData extends Data
{
    /**
     * @param  list<HoldableTicketTypeData>  $ticketTypes
     */
    public function __construct(
        public string $id,
        #[DataCollectionOf(HoldableTicketTypeData::class)]
        public array $ticketTypes,
        public OnSalePolicyData $onSalePolicy,
    ) {}

    public static function fromModel(Event $event): self
    {
        return new self(
            $event->id,
            $event->ticketTypes
                ->map(fn (TicketType $ticketType): HoldableTicketTypeData => HoldableTicketTypeData::fromModel($ticketType))
                ->all(),
            $event->on_sale_policy,
        );
    }
}
