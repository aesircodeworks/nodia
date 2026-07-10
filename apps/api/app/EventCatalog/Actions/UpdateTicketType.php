<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\TicketTypeData;
use App\EventCatalog\Data\UpdateTicketTypeData;
use App\EventCatalog\Events\EventUpdated;
use App\EventCatalog\Exceptions\CurrencyMismatchException;
use App\EventCatalog\Models\TicketType;
use App\Support\Outbox\OutboxRecorder;
use App\Tenancy\Actions\ResolveTenantSettlementCurrency;
use Spatie\LaravelData\Optional;

/**
 * PATCH /v1/ticket-types/{ticket_type} (stage-05a plan, task breakdown
 * item 8). Every field is optional; a field absent from the payload is
 * left untouched, mirroring App\EventCatalog\Actions\UpdateEvent.
 * EventUpdated is recorded for the parent event on every successful call,
 * unconditionally, matching UpdateEvent's own no-no-op-skip precedent.
 */
final class UpdateTicketType
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly ResolveTenantSettlementCurrency $resolveSettlementCurrency,
    ) {}

    public function __invoke(TicketType $ticketType, UpdateTicketTypeData $data): TicketTypeData
    {
        $attributes = [];

        if (! $data->name instanceof Optional) {
            $attributes['name'] = $data->name;
        }

        if (! $data->price instanceof Optional) {
            $this->assertCurrencyMatchesSettlement($ticketType->tenant_id, $data->price->currency);
            $attributes['price'] = $data->price;
        }

        if (! $data->salesStart instanceof Optional) {
            $attributes['sales_start'] = $data->salesStart;
        }

        if (! $data->salesEnd instanceof Optional) {
            $attributes['sales_end'] = $data->salesEnd;
        }

        if (! $data->requiresSeat instanceof Optional) {
            $attributes['requires_seat'] = $data->requiresSeat;
        }

        $ticketType->update($attributes);

        $this->outbox->record(EventUpdated::fromEvent($ticketType->event));

        return TicketTypeData::fromModel($ticketType->refresh());
    }

    private function assertCurrencyMatchesSettlement(string $tenantId, string $currency): void
    {
        $settlementCurrency = ($this->resolveSettlementCurrency)($tenantId);

        if ($currency !== $settlementCurrency) {
            throw CurrencyMismatchException::between($settlementCurrency, $currency);
        }
    }
}
