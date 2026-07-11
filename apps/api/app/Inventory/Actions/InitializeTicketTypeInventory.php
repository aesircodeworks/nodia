<?php

namespace App\Inventory\Actions;

use App\Inventory\Models\TicketTypeInventory;

/**
 * Creates the counter row for a ticket type when it is given a quantity
 * (stage-06 plan, task breakdown item 2). Called by Catalog's create and
 * update ticket type Actions (task 3) for GA types, and by
 * MaterializeEventSeats (task 9) for seated types, which seeds `quantity`
 * at 0 and lets zoning adjust it. tenant_id is passed explicitly: a
 * context never touches another context's models, so Inventory cannot
 * resolve it from the ticket type row itself.
 */
final class InitializeTicketTypeInventory
{
    public function __invoke(string $tenantId, string $ticketTypeId, int $quantity): TicketTypeInventory
    {
        return TicketTypeInventory::query()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketTypeId,
            'quantity' => $quantity,
            'held' => 0,
            'sold' => 0,
        ]);
    }
}
