<?php

namespace App\Inventory\Http\Controllers;

use App\Inventory\Data\TicketTypeInventoryData;
use App\Inventory\Exceptions\TicketTypeInventoryNotFoundException;
use App\Inventory\Models\TicketTypeInventory;

/**
 * The tenant admin availability read surface for GA (stage-06 plan,
 * Endpoints "GET /v1/ticket-types/{ticket_type}/inventory"): lets a
 * tenant see the raw quantity/sold/held counters without database
 * access. Registered under the tenancy.admin group (Passport staff
 * bearer, X-Tenant-Id membership validation, events.view capability) via
 * App\Inventory\InventoryServiceProvider.
 */
class TicketTypeInventoryController
{
    public function show(string $ticket_type): TicketTypeInventoryData
    {
        $inventory = TicketTypeInventory::query()->where('ticket_type_id', $ticket_type)->first()
            ?? throw TicketTypeInventoryNotFoundException::forTicketType($ticket_type);

        return TicketTypeInventoryData::fromModel($inventory);
    }
}
