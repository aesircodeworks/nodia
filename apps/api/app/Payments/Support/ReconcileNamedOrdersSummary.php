<?php

namespace App\Payments\Support;

use App\Orders\Data\AwaitingPaymentOrderData;
use Illuminate\Support\Collection;

/**
 * Candidate/result snapshot for App\Payments\Actions\ReconcileNamedOrders
 * (stage-12 plan, Slice 5, task breakdown item 12), mirroring
 * App\Support\Outbox\OutboxFailedReplaySummary's own shape: orders
 * always carries the full matched set, resolved is zero for a dry run
 * (no writes issued) and set to what --execute actually resolved
 * through the state machine otherwise.
 */
final readonly class ReconcileNamedOrdersSummary
{
    /**
     * @param  Collection<int, AwaitingPaymentOrderData>  $orders
     */
    public function __construct(
        public Collection $orders,
        public int $resolved = 0,
    ) {}
}
