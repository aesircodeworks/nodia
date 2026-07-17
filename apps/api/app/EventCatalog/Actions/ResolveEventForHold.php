<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\HoldableEventData;
use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;

/**
 * The read-only seam App\Inventory\Actions\CreateHold calls to validate a
 * hold request against Catalog facts (stage-06 plan, Endpoints "POST
 * /v1/storefront/holds"), never by Inventory querying Event or TicketType
 * directly (system-design 3.1 boundary rule). Returns null for a
 * nonexistent or unpublished event, so the caller renders the same
 * event_not_found code either way and unpublished existence never leaks,
 * mirroring App\EventCatalog\Http\Controllers\StorefrontEventController's
 * own posture.
 */
final class ResolveEventForHold
{
    public function __invoke(string $eventId): ?HoldableEventData
    {
        $event = Event::query()
            ->where('status', EventStatus::Published)
            ->with('ticketTypes')
            ->find($eventId);

        return $event === null ? null : HoldableEventData::fromModel($event);
    }
}
