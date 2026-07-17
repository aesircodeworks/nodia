<?php

namespace App\Support\Outbox;

/**
 * Opt-in marker for consumers that require per-aggregate outbox sequence
 * order (system-design 9.2, stage-04 plan Slice 4). ProcessOutboxDelivery
 * consults OrderedConsumption for implementors and defers the job when a
 * same-aggregate predecessor is unprocessed or the event is younger than
 * the stability window. Unordered OutboxSubscriber handlers are unchanged.
 *
 * Stage 8b may extend ordering with a payload-derived key; Stage 4 keys
 * strictly on the envelope aggregate (aggregate_type, aggregate_id).
 */
interface OrderedOutboxSubscriber extends OutboxSubscriber {}
