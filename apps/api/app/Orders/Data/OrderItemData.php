<?php

namespace App\Orders\Data;

use App\Orders\Models\OrderItem;
use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One priced line in an OrderData response (stage-07 plan, Endpoints).
 */
#[MapName(SnakeCaseMapper::class)]
class OrderItemData extends Data
{
    /**
     * @param  list<string>|null  $attendeeNames
     */
    public function __construct(
        public string $ticketTypeId,
        public int $quantity,
        public Money $unitPrice,
        public ?array $attendeeNames,
    ) {}

    public static function fromModel(OrderItem $item): self
    {
        return new self(
            $item->ticket_type_id,
            $item->quantity,
            $item->unit_price,
            $item->attendee_names,
        );
    }
}
