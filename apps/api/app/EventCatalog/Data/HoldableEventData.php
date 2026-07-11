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
    ) {}

    public static function fromModel(Event $event): self
    {
        return new self(
            $event->id,
            $event->ticketTypes
                ->map(fn (TicketType $ticketType): HoldableTicketTypeData => HoldableTicketTypeData::fromModel($ticketType))
                ->all(),
        );
    }
}
