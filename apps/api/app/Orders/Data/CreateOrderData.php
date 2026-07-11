<?php

namespace App\Orders\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/storefront/orders request body (stage-07 plan, Endpoints).
 * The authenticated customer is derived from the bearer token, never
 * the body, mirroring CreateHoldData's posture. attendee_names is keyed
 * by ticket type id, one list of names per line, copied onto the order
 * items at conversion and onto tickets at issuance. Slice 5 adds the
 * optional promo_code field.
 */
#[MapName(SnakeCaseMapper::class)]
class CreateOrderData extends Data
{
    /**
     * @param  array<string, list<string>>|Optional  $attendeeNames
     */
    public function __construct(
        public string $holdId,
        public array|Optional $attendeeNames,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'hold_id' => ['required', 'uuid'],
            'attendee_names' => ['sometimes', 'array'],
            'attendee_names.*' => ['array'],
            'attendee_names.*.*' => ['string', 'max:255'],
        ];
    }
}
