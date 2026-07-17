<?php

namespace App\Support\Outbox;

use App\Support\Outbox\Models\OutboxDelivery;
use Illuminate\Support\Collection;

/**
 * Candidate/result snapshot for OutboxFailedReplay (stage-12 plan, Slice
 * 5, task breakdown item 11). OutboxFailedReplay::candidates() returns
 * one with both *Reenqueued counters at zero (a dry-run listing issues no
 * writes); replay() returns one with the counters set to what --execute
 * actually re-enqueued. failedJobs and strandedDeliveries always carry
 * the full matched set either way, so a caller can report "N matched, M
 * re-enqueued" even when M is zero.
 */
final readonly class OutboxFailedReplaySummary
{
    /**
     * @param  Collection<int, object>  $failedJobs
     * @param  Collection<int, OutboxDelivery>  $strandedDeliveries
     */
    public function __construct(
        public Collection $failedJobs,
        public Collection $strandedDeliveries,
        public int $failedJobsReenqueued = 0,
        public int $strandedDeliveriesReenqueued = 0,
    ) {}
}
