<?php

namespace App\EventCatalog\Http\Controllers;

use App\EventCatalog\Actions\CreateVenue;
use App\EventCatalog\Actions\UpdateVenue;
use App\EventCatalog\Data\CreateVenueData;
use App\EventCatalog\Data\UpdateVenueData;
use App\EventCatalog\Data\VenueData;
use App\EventCatalog\Exceptions\VenueNotFoundException;
use App\EventCatalog\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class VenueController
{
    public function store(CreateVenueData $data, CreateVenue $createVenue): JsonResponse
    {
        return response()->json($createVenue($data), 201);
    }

    /**
     * @return PaginatedDataCollection<int, VenueData>
     */
    public function index(Request $request): PaginatedDataCollection
    {
        // tenant_isolation RLS already scopes the result to the acting
        // tenant's own venues; allowed query-builder parameters are
        // filter[name], filter[city], and sort in (name, -name,
        // created_at, -created_at), unknown ones rejected with 400
        // invalid_query_parameter rather than ignored (stage-05a plan
        // endpoint table).
        $venues = QueryBuilder::for(Venue::class)
            ->allowedFilters(AllowedFilter::partial('name'), AllowedFilter::partial('city'))
            ->allowedSorts('name', 'created_at')
            ->defaultSort('-created_at')
            ->paginate()
            ->appends($request->query());

        return VenueData::collect($venues, PaginatedDataCollection::class);
    }

    public function show(string $venue): VenueData
    {
        return VenueData::fromModel($this->venueOrFail($venue));
    }

    public function update(string $venue, UpdateVenueData $data, UpdateVenue $updateVenue): VenueData
    {
        return $updateVenue($this->venueOrFail($venue), $data);
    }

    /**
     * A well-formed but nonexistent or foreign-tenant venue id renders the
     * same generic request.not_found problem a malformed id gets from
     * route-parameter matching: tenant_isolation RLS already makes another
     * tenant's venue invisible to a plain find(), so existence never leaks
     * (stage-05a plan endpoint table).
     */
    private function venueOrFail(string $venueId): Venue
    {
        return Venue::query()->find($venueId) ?? throw VenueNotFoundException::forId($venueId);
    }
}
