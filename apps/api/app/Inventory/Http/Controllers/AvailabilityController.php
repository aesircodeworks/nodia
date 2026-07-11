<?php

namespace App\Inventory\Http\Controllers;

use App\Inventory\Actions\GetEventAvailability;
use App\Inventory\Data\EventAvailabilityData;

/**
 * The storefront availability read surface (stage-06 plan, Endpoints "GET
 * /v1/storefront/events/{event}/availability"). Guest checkout: no
 * authentication is required, mirroring
 * App\Inventory\Http\Controllers\HoldController's own posture.
 */
class AvailabilityController
{
    public function index(string $event, GetEventAvailability $getEventAvailability): EventAvailabilityData
    {
        return $getEventAvailability($event);
    }
}
