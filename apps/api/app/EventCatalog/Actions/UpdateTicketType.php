<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\TicketTypeData;
use App\EventCatalog\Data\UpdateTicketTypeData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Events\EventUpdated;
use App\EventCatalog\Exceptions\CurrencyMismatchException;
use App\EventCatalog\Exceptions\EventImmutableException;
use App\EventCatalog\Exceptions\EventNotFoundException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Inventory\Actions\SetTicketTypeQuantity;
use App\Support\Outbox\OutboxRecorder;
use App\Tenancy\Actions\ResolveTenantSettlementCurrency;
use Illuminate\Validation\ValidationException;
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
        private readonly SetTicketTypeQuantity $setQuantity,
    ) {}

    public function __invoke(TicketType $ticketType, UpdateTicketTypeData $data): TicketTypeData
    {
        // Lock the parent event and re-read its status before writing, so a
        // cancel committing concurrently cannot modify a ticket type on a
        // now-canceled event (UpdateEvent docblock: the immutability guard is
        // a conditional write, not a controller read-then-write).
        $event = Event::query()->whereKey($ticketType->event_id)->lockForUpdate()->first()
            ?? throw EventNotFoundException::forId($ticketType->event_id);

        if ($event->status === EventStatus::Canceled) {
            throw EventImmutableException::forId($event->id);
        }

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

        if (! $data->maxPerCustomer instanceof Optional) {
            $attributes['max_per_customer'] = $data->maxPerCustomer;
        }

        // The effective requires_seat value after this PATCH: the given
        // value, or the persisted one when the payload leaves it untouched
        // (UpdateTicketTypeData docblock: a Data class cannot read the
        // model, so this re-check belongs here).
        $requiresSeat = $data->requiresSeat instanceof Optional ? $ticketType->requires_seat : $data->requiresSeat;

        if ($requiresSeat && ! $data->quantity instanceof Optional) {
            throw ValidationException::withMessages([
                'quantity' => ['The quantity field is not allowed for a ticket type that requires seat selection.'],
            ]);
        }

        $wasSeated = $ticketType->requires_seat;

        $ticketType->update($attributes);

        if (! $requiresSeat && ! $data->quantity instanceof Optional) {
            ($this->setQuantity)($ticketType->tenant_id, $ticketType->id, $data->quantity);
        } elseif ($requiresSeat && ! $wasSeated) {
            // Converting a GA type to seated: its GA counter still carries the
            // old absolute quantity, which is meaningless for a seated type
            // whose availability comes from zoned event_seats. Reset it to 0,
            // matching MaterializeEventSeats' zero seed on publish, so the
            // seated type never reports stale GA availability. The conditional
            // decrease guards against zeroing out already sold or held units.
            ($this->setQuantity)($ticketType->tenant_id, $ticketType->id, 0);
        }

        $this->outbox->record(EventUpdated::fromEvent($event));

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
