<?php

namespace App\Inventory\Http\Controllers;

use App\Inventory\Actions\GetQueueEntry;
use App\Inventory\Actions\JoinQueue;
use App\Inventory\Data\JoinQueueData;
use App\Inventory\Data\QueueEntryData;
use Illuminate\Http\JsonResponse;

/**
 * The storefront waiting-room surface (stage-10 plan, Endpoints
 * "Storefront"). Unauthenticated like every other storefront route in
 * this context; the entrant id store() returns is itself the capability
 * show() accepts, the UUIDv7 anti-enumeration posture (section 14.4).
 */
class QueueEntryController
{
    public function store(string $event, JoinQueueData $data, JoinQueue $joinQueue): JsonResponse
    {
        return response()->json($joinQueue($event, $data), 201);
    }

    public function show(string $entry, GetQueueEntry $getQueueEntry): QueueEntryData
    {
        return $getQueueEntry($entry);
    }
}
