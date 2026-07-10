<?php

namespace App\EventCatalog\Http\Controllers;

use App\EventCatalog\Actions\UpsertSeatMap;
use App\EventCatalog\Data\SeatMapData;
use App\EventCatalog\Data\SeatMapSummaryData;
use App\EventCatalog\Data\UpsertSeatMapData;
use App\EventCatalog\Exceptions\SeatMapNotFoundException;
use App\EventCatalog\Exceptions\VenueNotFoundException;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class SeatMapController
{
    public function store(string $venue, UpsertSeatMapData $data, UpsertSeatMap $upsertSeatMap): JsonResponse
    {
        return response()->json($upsertSeatMap->create($this->venueOrFail($venue), $data), 201);
    }

    /**
     * @return PaginatedDataCollection<int, SeatMapSummaryData>
     */
    public function index(string $venue, Request $request): PaginatedDataCollection
    {
        $model = $this->venueOrFail($venue);

        // tenant_isolation RLS plus the venue_id scope already limit the
        // result to this venue's own templates; allowed query-builder
        // parameters are filter[name] (partial match) and sort in (name,
        // -name, created_at, -created_at), unknown ones rejected with 400
        // invalid_query_parameter rather than ignored (stage-05b plan
        // endpoint table), mirroring VenueController::index().
        // withCount('seats') avoids an N+1 query per row for seat_count.
        $seatMaps = QueryBuilder::for(SeatMap::query()->where('venue_id', $model->id)->withCount('seats'))
            ->allowedFilters(AllowedFilter::partial('name'))
            ->allowedSorts('name', 'created_at')
            ->defaultSort('-created_at')
            ->paginate()
            ->appends($request->query());

        return SeatMapSummaryData::collect($seatMaps, PaginatedDataCollection::class);
    }

    public function show(string $seat_map): SeatMapData
    {
        return SeatMapData::fromModel($this->seatMapOrFail($seat_map));
    }

    /**
     * Full replace (stage-05b plan, task breakdown item 4): the not-found
     * lookup here is a plain find() under RLS, same as show()'s and
     * store()'s own venueOrFail()/seatMapOrFail() precedent; the Action's
     * own lockForUpdate() re-fetch inside its transaction is what actually
     * serializes concurrent replaces of the same map, not this lookup.
     */
    public function update(string $seat_map, UpsertSeatMapData $data, UpsertSeatMap $upsertSeatMap): SeatMapData
    {
        $seatMap = SeatMap::query()->find($seat_map) ?? throw SeatMapNotFoundException::forId($seat_map);

        return $upsertSeatMap->replace($seatMap, $data);
    }

    /**
     * Mirrors VenueController::venueOrFail(): a well-formed but nonexistent
     * or foreign-tenant venue id renders the generic request.not_found
     * problem, never a seat-map-specific code, so existence never leaks
     * (stage-05b plan, Endpoints: "404 (venue)").
     */
    private function venueOrFail(string $venueId): Venue
    {
        return Venue::query()->find($venueId) ?? throw VenueNotFoundException::forId($venueId);
    }

    /**
     * Mirrors venueOrFail(): a well-formed but nonexistent or
     * foreign-tenant seat map id renders the generic request.not_found
     * problem, never a seat-map-specific code (stage-05b plan, Endpoints:
     * "GET /v1/seat-maps/{seat_map} ... 404"). Seats are loaded through
     * SeatMap::seats()'s own default (section, row, number) ordering, so
     * SeatMapData::fromModel() always gets the deterministic sequence.
     */
    private function seatMapOrFail(string $seatMapId): SeatMap
    {
        $seatMap = SeatMap::query()->find($seatMapId) ?? throw SeatMapNotFoundException::forId($seatMapId);

        $seatMap->setRelation('seats', $seatMap->seats()->get());

        return $seatMap;
    }
}
