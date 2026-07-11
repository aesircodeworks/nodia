<?php

namespace App\EventCatalog\Actions;

use App\EventCatalog\Data\AsyncPaymentPolicyData;
use App\EventCatalog\Models\Event;

/**
 * A cross-context read for Payments' slow-method filtering (stage-08a
 * plan, Endpoints; system-design 7.4): Payments never queries the
 * events table directly (section 3.1 boundary rule), so the per-event
 * async payment policy is exposed as an Action. A missing event yields
 * the default policy: the caller has already resolved the order, so the
 * event exists; defaulting keeps the offer total rather than failing.
 */
final class ResolveAsyncPaymentPolicy
{
    public function __invoke(string $eventId): AsyncPaymentPolicyData
    {
        $event = Event::query()->find($eventId);

        return $event === null ? new AsyncPaymentPolicyData : $event->async_payment_policy;
    }
}
