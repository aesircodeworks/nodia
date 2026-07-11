<?php

namespace App\Inventory\Http\Controllers;

use App\EventCatalog\Actions\ResolveEventForHold;
use App\Inventory\Actions\UpdateEventSeats;
use App\Inventory\Data\EventSeatBatchData;
use App\Inventory\Data\EventSeatData;
use App\Inventory\Data\UpdateEventSeatsData;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Models\EventSeat;
use Illuminate\Http\Request;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The tenant admin seat management surface (stage-06 plan, Endpoints
 * "GET /v1/events/{event}/seats", "PATCH /v1/events/{event}/seats").
 * Registered under the tenancy.admin group (Passport staff bearer,
 * X-Tenant-Id membership validation) gated by the events.manage_seating
 * capability via App\Inventory\InventoryServiceProvider.
 */
class EventSeatController
{
    public function __construct(private readonly ResolveEventForHold $resolveEvent) {}

    /**
     * @return PaginatedDataCollection<int, EventSeatData>
     */
    public function index(string $event, Request $request): PaginatedDataCollection
    {
        $eventId = $this->eventIdOrFail($event);

        // tenant_isolation RLS plus the event_id scope already limit the
        // result to this event's own seats; allowed query-builder
        // parameters are filter[status] and filter[ticket_type_id] (both
        // exact match), unknown ones rejected with 400
        // invalid_query_parameter rather than ignored (stage-06 plan,
        // Endpoints: "filter allowlist and rejection of unknown filters"),
        // mirroring App\EventCatalog\Http\Controllers\EventController::index.
        $seats = QueryBuilder::for(EventSeat::query()->where('event_id', $eventId))
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('ticket_type_id'),
            )
            ->defaultSort('created_at')
            ->paginate()
            ->appends($request->query());

        return EventSeatData::collect($seats, PaginatedDataCollection::class);
    }

    public function update(string $event, UpdateEventSeatsData $data, UpdateEventSeats $updateEventSeats): EventSeatBatchData
    {
        $eventId = $this->eventIdOrFail($event);

        $seats = $updateEventSeats($eventId, $data);

        return new EventSeatBatchData(array_map(
            fn (EventSeat $seat): EventSeatData => EventSeatData::fromModel($seat),
            $seats,
        ));
    }

    /**
     * A well-formed but nonexistent, unpublished, or foreign-tenant event
     * id renders the same event_not_found code, mirroring
     * App\Inventory\Actions\GetStorefrontEventSeats's own posture:
     * materialized seats only ever exist on a published event.
     */
    private function eventIdOrFail(string $eventId): string
    {
        $event = ($this->resolveEvent)($eventId) ?? throw HoldEventNotFoundException::forId($eventId);

        return $event->id;
    }
}
