<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\OnSalePolicyData;
use App\EventCatalog\Models\Event;

/**
 * The read-only, batched, cross-tenant seam App\Inventory\Actions\
 * RunGatekeeperTick calls to read on_sale_policy for every event
 * App\Inventory\Support\OnSaleQueue::activeMembers() names (stage-10
 * plan, task breakdown item 8: "reading flagged-event configuration
 * across tenants through the Stage 2 cross-tenant database role exactly
 * as the Stage 4 queue worker bootstrap does" -- App\Support\Outbox\Jobs\
 * ProcessOutboxDelivery's own asPlatform() read before any tenant
 * context is established), never by Inventory querying
 * App\EventCatalog\Models\Event directly (system-design 3.1 boundary
 * rule). One query covers every active event regardless of which
 * tenant(s) it belongs to, since the gatekeeper's own single tick has no
 * reason to open one transaction per tenant just to read configuration.
 * Missing event ids are simply absent from the returned map, letting the
 * caller treat a deleted event the same as one no longer flagged
 * high-demand.
 */
final class ResolveOnSalePoliciesForEvents
{
    /**
     * @param  list<string>  $eventIds
     * @return array<string, OnSalePolicyData>
     */
    public function __invoke(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return Event::query()
            ->whereIn('id', $eventIds)
            ->get(['id', 'on_sale_policy'])
            ->mapWithKeys(fn (Event $event): array => [$event->id => $event->on_sale_policy])
            ->all();
    }
}
