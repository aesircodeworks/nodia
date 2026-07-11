<?php

namespace App\Inventory\Http\Controllers;

use App\Inventory\Actions\GetStorefrontEventSeats;
use App\Inventory\Data\StorefrontEventSeatMapData;

/**
 * GET /v1/storefront/events/{event}/seats (stage-06 plan, Endpoints).
 * Guest checkout: no authentication is required, mirroring
 * App\Inventory\Http\Controllers\AvailabilityController's own posture.
 */
class StorefrontEventSeatController
{
    public function index(string $event, GetStorefrontEventSeats $getStorefrontEventSeats): StorefrontEventSeatMapData
    {
        return $getStorefrontEventSeats($event);
    }
}
