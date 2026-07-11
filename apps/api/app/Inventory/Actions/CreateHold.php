<?php

namespace App\Inventory\Actions;

use App\EventCatalog\Actions\ResolveEventForHold;
use App\EventCatalog\Data\HoldableEventData;
use App\EventCatalog\Data\HoldableTicketTypeData;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Data\HoldData;
use App\Inventory\Data\HoldItemInputData;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Events\HoldCreated;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Exceptions\InsufficientHoldInventoryException;
use App\Inventory\Exceptions\SalesWindowClosedException;
use App\Inventory\Exceptions\TicketTypeNotInEventException;
use App\Inventory\Models\Hold;
use App\Inventory\Models\HoldItem;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * POST /v1/storefront/holds, the GA path (stage-06 plan, TDD sequencing
 * Slice 2; Endpoints "POST /v1/storefront/holds"). Runs entirely inside
 * the ambient request transaction App\Tenancy\Http\Middleware\
 * ResolveTenantFromHost already opened, so the hold row, its items, the
 * per-item held-increment guard, and the HoldCreated outbox row all
 * commit or roll back together. Seated items (requires_seat true) are out
 * of scope for this task (Slice 6 adds seat handling); a requires_seat
 * ticket type simply falls through to the same held-increment guard as a
 * GA type, which is harmless because MaterializeEventSeats (a later
 * task) seeds every seated counter's quantity at 0 until zoning assigns
 * seats to it, so an unmaterialized or unzoned seated type always fails
 * insufficient_inventory rather than silently overselling.
 */
final class CreateHold
{
    private const TTL_MINUTES = 10;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OutboxRecorder $outbox,
        private readonly ResolveEventForHold $resolveEvent,
    ) {}

    public function __invoke(CreateHoldData $data, ?string $customerId): HoldData
    {
        $tenantId = $this->tenantContext->tenantId();

        $event = ($this->resolveEvent)($data->eventId) ?? throw HoldEventNotFoundException::forId($data->eventId);

        $now = Date::now();

        foreach ($data->items as $item) {
            $this->assertHoldable($event, $item, $now);
        }

        $hold = Hold::create([
            'tenant_id' => $tenantId,
            'event_id' => $data->eventId,
            'customer_id' => $customerId,
            'status' => HoldStatus::Active,
            'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES),
        ]);

        foreach ($data->items as $item) {
            $this->claim($item->ticketTypeId, $item->quantity);

            HoldItem::create([
                'tenant_id' => $tenantId,
                'hold_id' => $hold->id,
                'ticket_type_id' => $item->ticketTypeId,
                'quantity' => $item->quantity,
            ]);
        }

        $hold->setRelation('items', $hold->items()->get());

        $this->outbox->record(HoldCreated::fromHold($hold));

        return HoldData::fromModel($hold);
    }

    private function assertHoldable(HoldableEventData $event, HoldItemInputData $item, CarbonInterface $now): void
    {
        $ticketType = $this->findTicketType($event, $item->ticketTypeId)
            ?? throw TicketTypeNotInEventException::forId($item->ticketTypeId);

        if ($ticketType->salesStart !== null && $now->lt($ticketType->salesStart)) {
            throw SalesWindowClosedException::forTicketType($item->ticketTypeId);
        }

        if ($ticketType->salesEnd !== null && $now->gt($ticketType->salesEnd)) {
            throw SalesWindowClosedException::forTicketType($item->ticketTypeId);
        }
    }

    private function findTicketType(HoldableEventData $event, string $ticketTypeId): ?HoldableTicketTypeData
    {
        foreach ($event->ticketTypes as $ticketType) {
            if ($ticketType->id === $ticketTypeId) {
                return $ticketType;
            }
        }

        return null;
    }

    /**
     * The held-increment guard (stage-06 plan, Data model
     * "ticket_type_inventory"): `UPDATE ticket_type_inventory SET held =
     * held + :n WHERE ticket_type_id = :id AND sold + held + :n <=
     * quantity`, a conditional UPDATE checked by affected-row count,
     * never a read-then-write existence check (master plan test-first
     * rule 2).
     */
    private function claim(string $ticketTypeId, int $quantity): void
    {
        $affected = TicketTypeInventory::query()
            ->where('ticket_type_id', $ticketTypeId)
            ->whereRaw('sold + held + ? <= quantity', [$quantity])
            ->update([
                'held' => DB::raw(sprintf('held + %d', $quantity)),
                'updated_at' => Date::now(),
            ]);

        if ($affected === 0) {
            throw InsufficientHoldInventoryException::forTicketType($ticketTypeId);
        }
    }
}
