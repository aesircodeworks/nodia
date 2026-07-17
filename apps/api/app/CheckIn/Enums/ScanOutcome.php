<?php

namespace App\CheckIn\Enums;

/**
 * The three outcomes App\CheckIn\Actions\ReconcileOfflineScans can
 * report for one scan in a batch (stage-09 plan, Endpoints "POST
 * /v1/check-in-batches"). Accepted and Duplicate line up with the
 * persisted App\CheckIn\Enums\CheckInResult values; Rejected has no
 * persisted row at all, which is why rejected scans are re-verified
 * from scratch on resubmission rather than replayed from a stored row.
 */
enum ScanOutcome: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
}
