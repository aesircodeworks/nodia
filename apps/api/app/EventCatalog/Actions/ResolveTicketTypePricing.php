<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\PricedTicketTypeData;
use App\EventCatalog\Models\TicketType;

/**
 * The read-only seam App\Orders\Actions\ConvertHoldToOrder calls to
 * price hold items from Catalog facts (stage-07 plan, Slice 1), never
 * by Orders querying TicketType directly (system-design 3.1 boundary
 * rule), mirroring ResolveEventForHold. Unknown ids are simply absent
 * from the result; the caller decides what a gap means.
 */
final class ResolveTicketTypePricing
{
    /**
     * @param  list<string>  $ticketTypeIds
     * @return array<string, PricedTicketTypeData> keyed by ticket type id
     */
    public function __invoke(array $ticketTypeIds): array
    {
        return TicketType::query()
            ->whereIn('id', $ticketTypeIds)
            ->get()
            ->mapWithKeys(fn (TicketType $ticketType): array => [
                $ticketType->id => PricedTicketTypeData::fromModel($ticketType),
            ])
            ->all();
    }
}
