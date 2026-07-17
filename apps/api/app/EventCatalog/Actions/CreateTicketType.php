<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\CreateTicketTypeData;
use App\EventCatalog\Data\TicketTypeData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Events\EventUpdated;
use App\EventCatalog\Exceptions\CurrencyMismatchException;
use App\EventCatalog\Exceptions\EventImmutableException;
use App\EventCatalog\Exceptions\EventNotFoundException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\InitializeTicketTypeInventory;
use App\Inventory\Actions\SetTicketTypeQuantity;
use App\Support\Outbox\OutboxRecorder;
use App\Tenancy\Actions\ResolveTenantSettlementCurrency;
use Illuminate\Validation\ValidationException;
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
        private readonly SetTicketTypeQuantity $setQuantity,
        private readonly InitializeTicketTypeInventory $initializeInventory,
    ) {}

    public function __invoke(Event $event, CreateTicketTypeData $data): TicketTypeData
    {
        // Lock the parent event and re-read its status before writing, so a
        // cancel committing concurrently cannot slip a ticket type onto a
        // canceled event (the immutability guard is a conditional write, not
        // a controller read-then-write; UpdateEvent docblock).
        $event = Event::query()->whereKey($event->getKey())->lockForUpdate()->first()
            ?? throw EventNotFoundException::forId((string) $event->getKey());

        if ($event->status === EventStatus::Canceled) {
            throw EventImmutableException::forId($event->id);
        }

        $this->assertCurrencyMatchesSettlement($event->tenant_id, $data->price->currency);

        $requiresSeat = $data->requiresSeat instanceof Optional ? false : $data->requiresSeat;

        if ($requiresSeat && ! $data->quantity instanceof Optional) {
            throw ValidationException::withMessages([
                'quantity' => ['The quantity field is not allowed for a ticket type that requires seat selection.'],
            ]);
        }

        $ticketType = TicketType::create([
            'tenant_id' => $event->tenant_id,
            'event_id' => $event->id,
            'name' => $data->name,
            'price' => $data->price,
            'sales_start' => $data->salesStart,
            'sales_end' => $data->salesEnd,
            'requires_seat' => $requiresSeat,
            'max_per_customer' => $data->maxPerCustomer instanceof Optional ? null : $data->maxPerCustomer,
        ]);

        if (! $requiresSeat) {
            $quantity = $data->quantity instanceof Optional ? 0 : $data->quantity;
            ($this->setQuantity)($event->tenant_id, $ticketType->id, $quantity);
        } elseif ($event->status === EventStatus::Published) {
            // On a draft event, seated counters are seeded at 0 by
            // MaterializeEventSeats on publish (task 9); a seated type added
            // after publish never reaches that path, so its counter is seeded
            // here at 0 for zoning to adjust, otherwise availability and holds
            // treat it as permanently unavailable.
            ($this->initializeInventory)($event->tenant_id, $ticketType->id, 0);
        }

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
