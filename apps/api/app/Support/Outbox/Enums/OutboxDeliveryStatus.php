<?php

namespace App\Support\Outbox\Enums;

/**
 * outbox_deliveries.status (system-design 9.2, stage-04 plan Data model).
 * Status columns are strings backed by a PHP enum; the enum is the
 * authoritative list of states (data-conventions). Pending means the
 * subscriber has not yet applied its effect; processed means the
 * conditional mark-processed transition has committed for this delivery.
 */
enum OutboxDeliveryStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
}
