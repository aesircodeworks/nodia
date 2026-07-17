<?php

namespace App\EventCatalog\Http\Controllers;

use App\EventCatalog\Actions\CreateTicketType;
use App\EventCatalog\Actions\UpdateTicketType;
use App\EventCatalog\Data\CreateTicketTypeData;
use App\EventCatalog\Data\TicketTypeData;
use App\EventCatalog\Data\UpdateTicketTypeData;
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
        // The canceled-event immutability guard lives in CreateTicketType as
        // a locking recheck on the parent event, not here (UpdateEvent
        // docblock: conditional write, never a controller read-then-write).
        return response()->json($createTicketType($this->eventOrFail($event), $data), 201);
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
        return $updateTicketType($this->ticketTypeOrFail($ticket_type), $data);
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
}
