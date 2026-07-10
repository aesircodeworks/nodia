<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\CreateTicketTypeData;
use App\EventCatalog\Data\TicketTypeData;
use App\EventCatalog\Events\EventUpdated;
use App\EventCatalog\Exceptions\CurrencyMismatchException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Support\Outbox\OutboxRecorder;
use App\Tenancy\Actions\ResolveTenantSettlementCurrency;
use Spatie\LaravelData\Optional;

/**
 * POST /v1/events/{event}/ticket-types (stage-05a plan, task breakdown
 * item 8). The whole admin request already runs inside one database
 * transaction (App\Tenancy\Http\Middleware\ResolveTenantFromHeader wraps
 * the entire handler in TenantTransaction::asTenant()), mirroring
 * App\EventCatalog\Actions\CreateEvent; EventUpdated is recorded for the
 * parent event in that same transaction, since the section 9.3 registry
 * has no ticket-type event (stage-05a plan, Domain events).
 */
final class CreateTicketType
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly ResolveTenantSettlementCurrency $resolveSettlementCurrency,
    ) {}

    public function __invoke(Event $event, CreateTicketTypeData $data): TicketTypeData
    {
        $this->assertCurrencyMatchesSettlement($event->tenant_id, $data->price->currency);

        $ticketType = TicketType::create([
            'tenant_id' => $event->tenant_id,
            'event_id' => $event->id,
            'name' => $data->name,
            'price' => $data->price,
            'sales_start' => $data->salesStart,
            'sales_end' => $data->salesEnd,
            'requires_seat' => $data->requiresSeat instanceof Optional ? false : $data->requiresSeat,
        ]);

        $this->outbox->record(EventUpdated::fromEvent($event));

        return TicketTypeData::fromModel($ticketType);
    }

    /**
     * The boundary read this task adds (stage-05a plan, task breakdown
     * item 8): EventCatalog never touches App\Tenancy\Models\Tenant
     * directly (section 3.1), so the tenant's settlement currency is
     * resolved through App\Tenancy\Actions\ResolveTenantSettlementCurrency
     * (task-05) instead.
     */
    private function assertCurrencyMatchesSettlement(string $tenantId, string $currency): void
    {
        $settlementCurrency = ($this->resolveSettlementCurrency)($tenantId);

        if ($currency !== $settlementCurrency) {
            throw CurrencyMismatchException::between($settlementCurrency, $currency);
        }
    }
}
