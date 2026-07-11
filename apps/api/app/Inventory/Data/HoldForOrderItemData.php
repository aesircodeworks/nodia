<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * One line of a HoldForOrderData read model (stage-07 plan, Slice 1).
 * seatIds carries the event_seats ids currently claimed by the hold for
 * this ticket type, so Stage 7's ticket issuance can assign seats
 * without ever reading Inventory's tables.
 */
#[Hidden]
class HoldForOrderItemData extends Data
{
    /**
     * @param  list<string>  $seatIds
     */
    public function __construct(
        public string $ticketTypeId,
        public int $quantity,
        public array $seatIds,
    ) {}
}
