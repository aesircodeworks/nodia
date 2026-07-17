<?php

namespace App\Inventory\Data;

use App\Inventory\Models\HoldItem;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One line item in a HoldData response (stage-06 plan, Endpoints "POST
 * /v1/storefront/holds": "items (HoldItemData[])").
 */
#[MapName(SnakeCaseMapper::class)]
class HoldItemData extends Data
{
    public function __construct(
        public string $ticketTypeId,
        public int $quantity,
    ) {}

    public static function fromModel(HoldItem $item): self
    {
        return new self($item->ticket_type_id, $item->quantity);
    }
}
