<?php

namespace App\Inventory\Data;

use App\Inventory\Models\TicketTypeInventory;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * GET /v1/ticket-types/{ticket_type}/inventory response shape (stage-06
 * plan, Endpoints): the raw ticket_type_inventory counter row for one
 * ticket type, letting a tenant see counters without database access.
 */
#[MapName(SnakeCaseMapper::class)]
class TicketTypeInventoryData extends Data
{
    public function __construct(
        public int $quantity,
        public int $sold,
        public int $held,
    ) {}

    public static function fromModel(TicketTypeInventory $inventory): self
    {
        return new self($inventory->quantity, $inventory->sold, $inventory->held);
    }
}
