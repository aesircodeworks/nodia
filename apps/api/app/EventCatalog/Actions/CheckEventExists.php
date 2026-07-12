<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Models\Event;

/**
 * The read-only seam other contexts call to confirm an event id resolves
 * to a visible row for the acting tenant, without importing
 * App\EventCatalog\Models directly (boundary rule, system-design 3.1).
 * No status filter: unlike App\EventCatalog\Actions\ResolveEventForHold
 * (storefront, published-only), staff surfaces such as the stage-09
 * signing-key endpoints operate on any of the tenant's own events, draft
 * or published; tenant_isolation RLS already scopes the result.
 */
final class CheckEventExists
{
    public function __invoke(string $eventId): bool
    {
        return Event::query()->whereKey($eventId)->exists();
    }
}
