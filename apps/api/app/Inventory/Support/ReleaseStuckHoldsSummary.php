<?php

namespace App\Inventory\Support;

use App\Inventory\Models\Hold;
use Illuminate\Support\Collection;

/**
 * Candidate/result snapshot for App\Inventory\Actions\ReleaseStuckHolds
 * (stage-12 plan, Slice 5, task breakdown item 13), mirroring
 * App\Support\Outbox\OutboxFailedReplaySummary and
 * App\Payments\Support\ReconcileNamedOrdersSummary's own shape: holds
 * always carries the full matched set (status active, past expires_at),
 * released is zero for a dry run (no writes issued) and set to how many
 * of those holds carry status released after --execute, whether this
 * call or a racing sweeper actually performed the transition.
 */
final readonly class ReleaseStuckHoldsSummary
{
    /**
     * @param  Collection<int, Hold>  $holds
     */
    public function __construct(
        public Collection $holds,
        public int $released = 0,
    ) {}
}
