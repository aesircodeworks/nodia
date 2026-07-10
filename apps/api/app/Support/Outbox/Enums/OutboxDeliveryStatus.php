<?php

namespace App\Support\Outbox\Enums;

use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * outbox_deliveries.status (system-design 9.2, stage-04 plan Data model).
 * Status columns are strings backed by a PHP enum; the enum is the
 * authoritative list of states (data-conventions). Pending means the
 * subscriber has not yet applied its effect; processed means the
 * conditional mark-processed transition has committed for this delivery.
 * Hidden from TypeScript generation: delivery status is internal worker
 * state, not an API contract (same pattern as outbox event payloads).
 */
#[Hidden]
enum OutboxDeliveryStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
}
