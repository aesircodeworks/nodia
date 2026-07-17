<?php

namespace App\EventCatalog\Http\Controllers;

use App\EventCatalog\Actions\CancelEvent;
use App\EventCatalog\Actions\CreateEvent;
use App\EventCatalog\Actions\PublishEvent;
use App\EventCatalog\Actions\UpdateEvent;
use App\EventCatalog\Data\CreateEventData;
use App\EventCatalog\Data\EventData;
use App\EventCatalog\Data\UpdateEventData;
use App\EventCatalog\Exceptions\EventNotFoundException;
use App\EventCatalog\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

class EventController
{
    public function store(CreateEventData $data, CreateEvent $createEvent): JsonResponse
    {
        return response()->json($createEvent($data), 201);
    }

    /**
     * @return PaginatedDataCollection<int, EventData>
     */
    public function index(Request $request): PaginatedDataCollection
    {
        // tenant_isolation RLS already scopes the result to the acting
        // tenant's own events; allowed query-builder parameters are
        // filter[status], filter[venue_id], filter[is_virtual] (all exact
        // match), sort in (start_at, -start_at, created_at, -created_at),
        // and include=venue,ticket_types (the snake_case include name maps
        // to the ticketTypes relation); unknown ones rejected with 400
        // invalid_query_parameter rather than ignored.
        $events = QueryBuilder::for(Event::class)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('venue_id'),
                AllowedFilter::exact('is_virtual'),
            )
            ->allowedSorts('start_at', 'created_at')
            ->allowedIncludes('venue', AllowedInclude::relationship('ticket_types', 'ticketTypes'))
            ->defaultSort('-start_at')
            ->paginate()
            ->appends($request->query());

        return EventData::collect($events, PaginatedDataCollection::class);
    }

    public function show(string $event): EventData
    {
        $model = QueryBuilder::for(Event::query()->whereKey($event))
            ->allowedIncludes('venue', AllowedInclude::relationship('ticket_types', 'ticketTypes'))
            ->first();

        return EventData::fromModel($model ?? throw EventNotFoundException::forId($event));
    }

    public function update(string $event, UpdateEventData $data, UpdateEvent $updateEvent): EventData
    {
        // The canceled-event immutability guard lives in UpdateEvent as a
        // locking recheck, not here: a controller pre-check would be a
        // read-then-write racing a concurrent cancel (UpdateEvent docblock).
        return $updateEvent($this->eventOrFail($event), $data);
    }

    /**
     * No status pre-check here, unlike update(): the transition is
     * decided entirely by PublishEvent's own conditional UPDATE and its
     * affected-row count, never a read-then-write status check in the
     * controller (stage-05a plan, TDD sequencing, Slice 4).
     *
     * laravel-data's Responsable defaults a POST response to 201; publish
     * transitions an existing event, it does not create a resource, so the
     * 200 status is set explicitly rather than relying on that default.
     */
    public function publish(string $event, PublishEvent $publishEvent): JsonResponse
    {
        return response()->json($publishEvent($this->eventOrFail($event)));
    }

    /**
     * Same posture as publish(): CancelEvent's own conditional UPDATE
     * alone decides whether the transition applies, and the same 201-default
     * override applies since cancel transitions rather than creates.
     */
    public function cancel(string $event, CancelEvent $cancelEvent): JsonResponse
    {
        return response()->json($cancelEvent($this->eventOrFail($event)));
    }

    /**
     * A well-formed but nonexistent or foreign-tenant event id renders the
     * same generic request.not_found problem a malformed id gets from
     * route-parameter matching, mirroring VenueController::venueOrFail.
     */
    private function eventOrFail(string $eventId): Event
    {
        return Event::query()->find($eventId) ?? throw EventNotFoundException::forId($eventId);
    }
}
