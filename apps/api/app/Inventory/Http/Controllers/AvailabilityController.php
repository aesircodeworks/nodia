<?php

namespace App\Inventory\Http\Controllers;

use App\Inventory\Actions\GetCachedEventAvailability;
use App\Inventory\Data\EventAvailabilityData;

/**
 * The storefront availability read surface (stage-06 plan, Endpoints "GET
 * /v1/storefront/events/{event}/availability"; fronted by the stage-10
 * plan's clock-aware Redis read cache, task breakdown item 10). Guest
 * checkout: no authentication is required, mirroring
 * App\Inventory\Http\Controllers\HoldController's own posture.
 */
class AvailabilityController
{
    public function index(string $event, GetCachedEventAvailability $getCachedEventAvailability): EventAvailabilityData
    {
        return $getCachedEventAvailability($event);
    }
}
