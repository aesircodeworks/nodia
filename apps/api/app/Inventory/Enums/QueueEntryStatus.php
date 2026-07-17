<?php

namespace App\Inventory\Enums;

/**
 * A waiting-room entrant's lifecycle state (stage-10 plan, Endpoints
 * "POST /v1/storefront/events/{event}/queue-entries"): every join lands
 * waiting; admitted is reached only by the gatekeeper (stage-10 plan
 * task breakdown item 8, not built by this task), which pops an entrant
 * from Redis's waiting sorted set and moves it into the admitted one.
 * Status columns are strings backed by a PHP enum elsewhere in this
 * context (data-conventions); this one backs a Redis-derived response
 * field rather than a database column, but the same discipline applies.
 */
enum QueueEntryStatus: string
{
    case Waiting = 'waiting';
    case Admitted = 'admitted';
}
