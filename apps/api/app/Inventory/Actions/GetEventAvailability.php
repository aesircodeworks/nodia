<?php

namespace App\Inventory\Actions;

use App\EventCatalog\Actions\ResolveEventForHold;
use App\EventCatalog\Data\HoldableTicketTypeData;
use App\Inventory\Data\EventAvailabilityData;
use App\Inventory\Data\TicketTypeAvailabilityData;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Models\TicketTypeInventory;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * GET /v1/storefront/events/{event}/availability (stage-06 plan, TDD
 * sequencing Slice 3, task breakdown item 7). Reuses
 * App\EventCatalog\Actions\ResolveEventForHold, the same read-only seam
 * App\Inventory\Actions\CreateHold already depends on, so Inventory never
 * touches App\EventCatalog\Models\Event or TicketType directly
 * (system-design 3.1 boundary rule): a nonexistent or unpublished event
 * id renders the same event_not_found code either way. Database-backed
 * and authoritative; App\Inventory\Actions\GetCachedEventAvailability
 * fronts this with a clock-aware Redis read cache without changing the
 * contract (stage-10 plan, Endpoints, task breakdown item 10). Nothing
 * on the hold-creation path calls this cache, so it stays the sole
 * source of truth GetCachedEventAvailability falls back to on a miss.
 */
final class GetEventAvailability
{
    public function __construct(
        private readonly ResolveEventForHold $resolveEvent,
    ) {}

    public function __invoke(string $eventId): EventAvailabilityData
    {
        $event = ($this->resolveEvent)($eventId) ?? throw HoldEventNotFoundException::forId($eventId);

        $ticketTypeIds = array_map(fn (HoldableTicketTypeData $ticketType): string => $ticketType->id, $event->ticketTypes);

        $counters = TicketTypeInventory::query()
            ->whereIn('ticket_type_id', $ticketTypeIds)
            ->get()
            ->keyBy('ticket_type_id');

        $now = Date::now();

        $items = array_map(
            fn (HoldableTicketTypeData $ticketType): TicketTypeAvailabilityData => new TicketTypeAvailabilityData(
                $ticketType->id,
                $this->available($counters->get($ticketType->id)),
                $this->isOnSale($ticketType, $now),
            ),
            $event->ticketTypes,
        );

        return new EventAvailabilityData($eventId, $items);
    }

    private function available(?TicketTypeInventory $counter): int
    {
        if ($counter === null) {
            return 0;
        }

        return max(0, $counter->quantity - $counter->sold - $counter->held);
    }

    private function isOnSale(HoldableTicketTypeData $ticketType, CarbonInterface $now): bool
    {
        if ($ticketType->salesStart !== null && $now->lt($ticketType->salesStart)) {
            return false;
        }

        if ($ticketType->salesEnd !== null && $now->gt($ticketType->salesEnd)) {
            return false;
        }

        return true;
    }
}
