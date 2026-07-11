<?php

namespace App\Inventory\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One line item inside a CreateHoldData request (stage-06 plan, Endpoints
 * "POST /v1/storefront/holds": "items as [{ticket_type_id, quantity}]").
 * Nested inside a parent Data class, so this class's own rules() is the
 * only place its dot-path validation (items.*.ticket_type_id, etc.) can
 * be declared, mirroring App\EventCatalog\Data\SeatInputData's own
 * precedent.
 */
#[MapName(SnakeCaseMapper::class)]
class HoldItemInputData extends Data
{
    public function __construct(
        public string $ticketTypeId,
        public int $quantity,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'ticket_type_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
