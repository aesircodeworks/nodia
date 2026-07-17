<?php

namespace App\Payments\Actions;

use App\Orders\Actions\FindAwaitingPaymentOrders;
use App\Orders\Data\AwaitingPaymentOrderData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Payments\Support\ReconcileNamedOrdersSummary;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The core of payments:reconcile-orders (stage-12 plan, Slice 5, task
 * breakdown item 12): a dry-run-by-default, --operator-required,
 * activity-logged manual poll of named awaiting_payment orders, distinct
 * from the automatic payments:reconcile sweep
 * (App\Console\Commands\ReconcilePendingPaymentsCommand, Stage 8a) this
 * command's signature would otherwise collide with -- see this task's
 * journal entry for the naming deviation.
 *
 * candidates() issues no writes at all: a dry-run investigation is safe
 * to run freely. reconcile() additionally resolves the matched orders'
 * own initiated payments through App\Payments\Actions
 * \ReconcilePendingPayments::reconcile() on $execute -- the exact same
 * adapter-poll and conditional-UPDATE state machine the automatic sweep
 * uses, reused rather than duplicated -- and always records one activity
 * log entry under the sentinel platform tenant naming the operator, the
 * arguments, and the matched/resolved counts, whether or not $execute
 * was set, mirroring OutboxFailedReplay's own "a dry run is itself
 * auditable" posture (stage-12 plan, task breakdown item 6).
 */
final readonly class ReconcileNamedOrders
{
    public function __construct(
        private FindAwaitingPaymentOrders $findAwaitingPaymentOrders,
        private ReconcilePendingPayments $reconcilePendingPayments,
        private TenantTransaction $transactions,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  list<string>  $orderIds
     * @return Collection<int, AwaitingPaymentOrderData>
     */
    public function candidates(array $orderIds, ?string $tenantId, CarbonInterface $before): Collection
    {
        return $this->transactions->asPlatform(
            fn () => ($this->findAwaitingPaymentOrders)($orderIds, $tenantId, $before),
        );
    }

    /**
     * @param  list<string>  $orderIds
     */
    public function reconcile(string $operator, bool $execute, array $orderIds, ?string $tenantId, CarbonInterface $before): ReconcileNamedOrdersSummary
    {
        $orders = $this->candidates($orderIds, $tenantId, $before);

        $resolved = $execute ? $this->reconcilePendingPayments->reconcile($this->paymentsFor($orders)) : 0;

        $summary = new ReconcileNamedOrdersSummary($orders, $resolved);

        $this->transactions->asPlatform(function () use ($operator, $execute, $orderIds, $tenantId, $before, $summary): void {
            $this->activityLogger->record(
                description: sprintf('payments:reconcile-orders %s by %s', $execute ? 'executed' : 'previewed', $operator),
                event: 'payments_reconcile_orders_invoked',
                properties: [
                    'operator' => $operator,
                    'execute' => $execute,
                    'order_ids' => $orderIds,
                    'tenant_id' => $tenantId,
                    'before' => $before->toIso8601String(),
                    'orders_matched' => $summary->orders->count(),
                    'orders_resolved' => $summary->resolved,
                ],
            );
        });

        return $summary;
    }

    /**
     * @param  Collection<int, AwaitingPaymentOrderData>  $orders
     * @return Collection<int, Payment>
     */
    private function paymentsFor(Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        return $this->transactions->asPlatform(
            fn () => Payment::query()
                ->select('id', 'tenant_id', 'gateway', 'gateway_reference')
                ->whereIn('order_id', $orders->pluck('id'))
                ->where('status', PaymentStatus::Initiated->value)
                ->get(),
        );
    }
}
