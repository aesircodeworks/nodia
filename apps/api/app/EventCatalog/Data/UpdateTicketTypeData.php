<?php

namespace App\EventCatalog\Data;

use App\EventCatalog\Data\Concerns\ValidatesTicketTypeInvariants;
use App\Support\Money\Money;
use Illuminate\Validation\Validator;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * PATCH /v1/ticket-types/{ticket_type} (stage-05a plan, task breakdown
 * item 8). Every field is Optional; a field absent from the payload is
 * left untouched, mirroring UpdateEventData/UpdateVenueData. sales_start
 * and sales_end each independently Optional, but
 * ValidatesTicketTypeInvariants::addSalesWindowErrors requires them given
 * together here (requireTogether: true) since a partial PATCH cannot
 * merge against the target TicketType's stored columns from a static Data
 * class; see that trait's own docblock.
 */
#[MapName(SnakeCaseMapper::class)]
class UpdateTicketTypeData extends Data
{
    use ValidatesTicketTypeInvariants;

    public function __construct(
        public string|Optional $name,
        public Money|Optional $price,
        public string|Optional|null $salesStart,
        public string|Optional|null $salesEnd,
        public bool|Optional $requiresSeat,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'filled', 'max:255'],
            'price' => ['sometimes', 'array'],
            'price.amount' => ['required_with:price', 'integer', 'min:0'],
            'price.currency' => ['required_with:price', 'string', 'regex:/^[A-Z]{3}$/'],
            'sales_start' => ['sometimes', 'nullable', 'date'],
            'sales_end' => ['sometimes', 'nullable', 'date'],
            'requires_seat' => ['sometimes', 'boolean'],
        ];
    }

    public static function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            self::addSalesWindowErrors($validator, $validator->getData(), requireTogether: true);
        });
    }
}
