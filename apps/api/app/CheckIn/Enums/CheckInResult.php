<?php

namespace App\CheckIn\Enums;

/**
 * Accepted rows are structurally at most one per ticket, backstopped by
 * the check_ins_accepted_ticket_idx partial unique index; every other
 * scan for that ticket persists as Duplicate rather than being dropped
 * (stage-09 plan, "Batch reconciliation for offline scan queues").
 */
enum CheckInResult: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
}
