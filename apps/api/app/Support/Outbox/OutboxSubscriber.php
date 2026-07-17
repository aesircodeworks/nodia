<?php

namespace App\Support\Outbox;

use App\Support\Outbox\Models\OutboxEvent;

/**
 * Contract for an outbox delivery consumer (system-design 9.2). Handlers
 * are invoked only after the matching outbox_deliveries row has been
 * conditionally marked processed in the same tenant-scoped transaction;
 * throwing rolls the effect and the progress mark back together.
 *
 * Production handlers register from owning context providers via
 * SubscriberRegistry. Test fixtures register only in test setup so they
 * never enter production routing (stage-04 plan, Risks).
 */
interface OutboxSubscriber
{
    public function handle(OutboxEvent $event): void;
}
