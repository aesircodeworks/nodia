<?php

namespace App\EventCatalog\Http\Controllers;

use App\EventCatalog\Actions\UpsertSeatMap;
use App\EventCatalog\Data\UpsertSeatMapData;
use App\EventCatalog\Exceptions\VenueNotFoundException;
use App\EventCatalog\Models\Venue;
use Illuminate\Http\JsonResponse;

class SeatMapController
{
    public function store(string $venue, UpsertSeatMapData $data, UpsertSeatMap $upsertSeatMap): JsonResponse
    {
        return response()->json($upsertSeatMap->create($this->venueOrFail($venue), $data), 201);
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
}
