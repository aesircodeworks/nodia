<?php

namespace App\Inventory\Actions;

use App\EventCatalog\Actions\ResolveEventForHold;
use App\EventCatalog\Actions\ResolveSeatsById;
use App\Inventory\Data\StorefrontEventSeatData;
use App\Inventory\Data\StorefrontEventSeatMapData;
use App\Inventory\Exceptions\EventNotSeatedException;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Models\EventSeat;

/**
 * GET /v1/storefront/events/{event}/seats (stage-06 plan, Slice 7, task
 * breakdown item 11). Reuses App\EventCatalog\Actions\ResolveEventForHold,
 * the same published-event seam App\Inventory\Actions\CreateHold and
 * GetEventAvailability already depend on, so a nonexistent or
 * unpublished event renders the same event_not_found code either way.
 * Seat metadata composition goes through
 * App\EventCatalog\Actions\ResolveSeatsById rather than any join against
 * Catalog's own seats table (system-design 3.1 boundary rule).
 * Database-backed and authoritative;
 * App\Inventory\Actions\GetCachedStorefrontEventSeats fronts this with a
 * clock-aware Redis read cache without changing the contract (stage-10
 * plan, Endpoints, task breakdown item 10).
 */
final class GetStorefrontEventSeats
{
    public function __construct(
        private readonly ResolveEventForHold $resolveEvent,
        private readonly ResolveSeatsById $resolveSeats,
    ) {}

    public function __invoke(string $eventId): StorefrontEventSeatMapData
    {
        $event = ($this->resolveEvent)($eventId) ?? throw HoldEventNotFoundException::forId($eventId);

        $eventSeats = EventSeat::query()->where('event_id', $event->id)->get();

        if ($eventSeats->isEmpty()) {
            throw EventNotSeatedException::forId($event->id);
        }

        $seats = ($this->resolveSeats)($eventSeats->pluck('seat_id')->all());

        $items = $eventSeats
            ->map(fn (EventSeat $eventSeat): ?StorefrontEventSeatData => isset($seats[$eventSeat->seat_id])
                ? StorefrontEventSeatData::fromModel($eventSeat, $seats[$eventSeat->seat_id])
                : null)
            ->filter()
            ->sort(fn (StorefrontEventSeatData $a, StorefrontEventSeatData $b): int => [$a->section, $a->row, $a->number] <=> [$b->section, $b->row, $b->number])
            ->values()
            ->all();

        return new StorefrontEventSeatMapData($event->id, $items);
    }
}
