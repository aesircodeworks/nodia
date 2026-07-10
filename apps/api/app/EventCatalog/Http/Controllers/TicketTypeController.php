<?php

namespace App\EventCatalog\Http\Controllers;

use App\EventCatalog\Actions\CreateTicketType;
use App\EventCatalog\Actions\UpdateTicketType;
use App\EventCatalog\Data\CreateTicketTypeData;
use App\EventCatalog\Data\TicketTypeData;
use App\EventCatalog\Data\UpdateTicketTypeData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Exceptions\EventImmutableException;
use App\EventCatalog\Exceptions\EventNotFoundException;
use App\EventCatalog\Exceptions\TicketTypeNotFoundException;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\QueryBuilder;

class TicketTypeController
{
    public function store(string $event, CreateTicketTypeData $data, CreateTicketType $createTicketType): JsonResponse
    {
        $model = $this->assertMutable($this->eventOrFail($event));

        return response()->json($createTicketType($model, $data), 201);
    }

    /**
     * @return PaginatedDataCollection<int, TicketTypeData>
     */
    public function index(string $event, Request $request): PaginatedDataCollection
    {
        $model = $this->eventOrFail($event);

        // stage-05a plan endpoint table: page pagination only, no
        // filter/sort/include allowlist for the nested ticket-types list.
        $ticketTypes = QueryBuilder::for(TicketType::query()->where('event_id', $model->id))
            ->paginate()
            ->appends($request->query());

        return TicketTypeData::collect($ticketTypes, PaginatedDataCollection::class);
    }

    public function show(string $ticket_type): TicketTypeData
    {
        return TicketTypeData::fromModel($this->ticketTypeOrFail($ticket_type));
    }

    public function update(string $ticket_type, UpdateTicketTypeData $data, UpdateTicketType $updateTicketType): TicketTypeData
    {
        $ticketType = $this->ticketTypeOrFail($ticket_type);
        $this->assertMutable($ticketType->event);

        return $updateTicketType($ticketType, $data);
    }

    /**
     * A well-formed but nonexistent or foreign-tenant event id renders the
     * same generic request.not_found problem a malformed id gets from
     * route-parameter matching, mirroring EventController::eventOrFail.
     */
    private function eventOrFail(string $eventId): Event
    {
        return Event::query()->find($eventId) ?? throw EventNotFoundException::forId($eventId);
    }

    /**
     * Mirrors TicketTypeNotFoundException's own not-found precedent for
     * the top-level ticket type resource (detail and update).
     */
    private function ticketTypeOrFail(string $ticketTypeId): TicketType
    {
        return TicketType::query()->find($ticketTypeId) ?? throw TicketTypeNotFoundException::forId($ticketTypeId);
    }

    /**
     * Ticket type mutations are gated on the parent event's own
     * immutability (stage-05a plan, endpoint table: "catalog.event_immutable
     * (409, event is canceled)"); the registry has no ticket-type status of
     * its own. Returns the event so store() can reuse it without a second
     * query.
     */
    private function assertMutable(Event $event): Event
    {
        if ($event->status === EventStatus::Canceled) {
            throw EventImmutableException::forId($event->id);
        }

        return $event;
    }
}
