<?php

namespace App\Inventory\Http\Controllers;

use App\Inventory\Actions\GetCachedStorefrontEventSeats;
use App\Inventory\Data\StorefrontEventSeatMapData;

/**
 * GET /v1/storefront/events/{event}/seats (stage-06 plan, Endpoints;
 * fronted by the stage-10 plan's clock-aware Redis read cache, task
 * breakdown item 10). Guest checkout: no authentication is required,
 * mirroring App\Inventory\Http\Controllers\AvailabilityController's own
 * posture.
 */
class StorefrontEventSeatController
{
    public function index(string $event, GetCachedStorefrontEventSeats $getCachedStorefrontEventSeats): StorefrontEventSeatMapData
    {
        return $getCachedStorefrontEventSeats($event);
    }
}
