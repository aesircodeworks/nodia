<?php

namespace App\Inventory\Actions;

use App\Inventory\Models\TicketTypeInventory;

/**
 * Sets a ticket type's counter row to an absolute `quantity`, called by
 * Catalog's create and update ticket type Actions for GA types (stage-06
 * plan, task breakdown item 3; Risks: "Quantity input ownership").
 * Catalog never touches App\Inventory\Models\TicketTypeInventory
 * directly, so this Action is the only path from a Catalog-supplied
 * absolute quantity to the delta App\Inventory\Actions\
 * AdjustInventoryQuantity's conditional UPDATE expects. Locks the
 * counter row first (or, if none exists yet, seeds one with
 * App\Inventory\Actions\InitializeTicketTypeInventory) so the delta is
 * computed against a value that cannot change underneath this call.
 */
final class SetTicketTypeQuantity
{
    public function __construct(
        private readonly InitializeTicketTypeInventory $initialize,
        private readonly AdjustInventoryQuantity $adjust,
    ) {}

    public function __invoke(string $tenantId, string $ticketTypeId, int $quantity): void
    {
        $row = TicketTypeInventory::query()
            ->where('ticket_type_id', $ticketTypeId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            ($this->initialize)($tenantId, $ticketTypeId, $quantity);

            return;
        }

        $delta = $quantity - $row->quantity;

        if ($delta !== 0) {
            ($this->adjust)($ticketTypeId, $delta);
        }
    }
}
