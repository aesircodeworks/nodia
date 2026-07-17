<?php

namespace App\Orders\Data;

use App\Orders\Models\Order;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * One order's export facts (stage-11 plan, task 15: the `orders` export
 * source). Plain internal class, never serialized to the wire, mirroring
 * TicketSaleFactsData's own posture: App\Reporting\Support\Export\
 * Sources\OrdersExportSource decomposes the four Money fields into their
 * CSV `*_amount`/`*_currency` column pairs itself, so this class keeps
 * each amount and its currency together as long as possible rather than
 * flattening early.
 */
final class OrderExportRowData
{
    public function __construct(
        public readonly string $id,
        public readonly string $eventId,
        public readonly string $status,
        public readonly Money $subtotal,
        public readonly Money $discount,
        public readonly Money $fees,
        public readonly Money $total,
        public readonly string $createdAt,
    ) {}

    public static function fromModel(Order $order): self
    {
        return new self(
            $order->id,
            $order->event_id,
            $order->status->value,
            $order->subtotal,
            $order->discount,
            $order->fees,
            $order->total,
            CarbonImmutable::instance($order->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
