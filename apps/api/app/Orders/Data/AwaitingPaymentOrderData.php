<?php

namespace App\Orders\Data;

use App\Orders\Models\Order;
use Carbon\CarbonImmutable;

/**
 * One awaiting_payment order's identity facts for the
 * payments:reconcile-orders operator command (stage-12 plan, Slice 5,
 * task breakdown item 12). Plain internal class, never serialized to the
 * wire, mirroring OrderExportRowData's own posture: Payments never
 * touches the orders table directly (event-conventions), so
 * App\Payments\Actions\ReconcileNamedOrders reads the candidate set back
 * through App\Orders\Actions\FindAwaitingPaymentOrders rather than
 * importing App\Orders\Models\Order.
 */
final class AwaitingPaymentOrderData
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $createdAt,
    ) {}

    public static function fromModel(Order $order): self
    {
        return new self(
            $order->id,
            $order->tenant_id,
            CarbonImmutable::instance($order->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
