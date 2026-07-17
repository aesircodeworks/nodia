<?php

namespace App\Orders\Actions;

use App\Orders\Data\AwaitingPaymentOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Candidate lookup for the payments:reconcile-orders operator command
 * (stage-12 plan, Slice 5, task breakdown item 12): the awaiting_payment
 * orders it would poll, bounded by an explicit order ID allowlist, an
 * optional tenant, and a required cutoff on created_at. Payments never
 * queries the orders table directly (event-conventions), so
 * App\Payments\Actions\ReconcileNamedOrders reads the candidate set back
 * through this Action, mirroring GetOrderEventIds's own cross-context
 * read shape. An empty allowlist means "no order filter," not "no
 * orders" (the caller still bounds the query with a tenant and/or the
 * required cutoff).
 */
final class FindAwaitingPaymentOrders
{
    /**
     * @param  list<string>  $orderIds
     * @return Collection<int, AwaitingPaymentOrderData>
     */
    public function __invoke(array $orderIds, ?string $tenantId, CarbonInterface $before): Collection
    {
        return Order::query()
            ->select('id', 'tenant_id', 'created_at')
            ->where('status', OrderStatus::AwaitingPayment->value)
            ->where('created_at', '<=', $before)
            ->when($orderIds !== [], fn ($query) => $query->whereIn('id', $orderIds))
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('created_at')
            ->get()
            ->map(AwaitingPaymentOrderData::fromModel(...))
            ->values();
    }
}
