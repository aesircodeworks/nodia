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
 * POST /v1/events/{event}/ticket-types (stage-05a plan, task breakdown
 * item 8). price is Money on the wire ({amount, currency}), cast and
 * transformed globally via config/data.php's Money::class entries (no
 * per-property attribute needed, unlike a fresh cast with no existing
 * config registration). Its currency must match the tenant's settlement
 * currency; that boundary check (App\Tenancy\Actions\
 * ResolveTenantSettlementCurrency) happens in
 * App\EventCatalog\Actions\CreateTicketType, never here:
 * ProblemRenderer renders every Illuminate\Validation\ValidationException
 * as the generic request.validation_failed, and catalog.currency_mismatch
 * needs its own stable code (mirroring catalog.event_immutable's own
 * precedent of a dedicated exception living outside Data validation).
 * sales_start/sales_end are both required present keys (nullable values),
 * mirroring venue_id's own "present, nullable" precedent on
 * CreateEventData, since the ticket_types_sales_window CHECK only fires
 * when both are set (task-06 isolation suite). quantity is stage-06's
 * additive field (Risks: "Quantity input ownership"): it does not
 * persist on TicketType at all, App\EventCatalog\Actions\CreateTicketType
 * passes it to the Inventory context's counter Actions instead, so it is
 * never read back through TicketTypeData::fromModel.
 */
#[MapName(SnakeCaseMapper::class)]
class CreateTicketTypeData extends Data
{
    use ValidatesTicketTypeInvariants;

    public function __construct(
        public string $name,
        public Money $price,
        public ?string $salesStart,
        public ?string $salesEnd,
        public bool|Optional $requiresSeat,
        public int|Optional $quantity,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'filled', 'max:255'],
            'price' => ['required', 'array'],
            'price.amount' => ['required', 'integer', 'min:0'],
            'price.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'sales_start' => ['present', 'nullable', 'date'],
            'sales_end' => ['present', 'nullable', 'date'],
            'requires_seat' => ['sometimes', 'boolean'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public static function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            self::addSalesWindowErrors($validator, $validator->getData(), requireTogether: false);
        });
    }
}
