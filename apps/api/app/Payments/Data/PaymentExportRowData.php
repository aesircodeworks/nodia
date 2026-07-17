<?php

namespace App\Payments\Data;

use App\Payments\Models\Payment;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * One payment's export facts (stage-12 plan, Slice 2; task breakdown
 * item 6: the data subject export assembler's `payments` category).
 * Plain internal class, never serialized to the wire, mirroring
 * App\Payments\Data\LedgerEntryExportRowData's own posture. Distinct
 * from LedgerEntryExportRowData: a ledger entry is a double-entry
 * accounting leg, not the payment attempt itself, and the export
 * document names "payments" as its own category, so this row carries
 * the payment's own facts (gateway, method, amount, status) rather than
 * standing in for the ledger.
 */
final class PaymentExportRowData
{
    public function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly string $gateway,
        public readonly string $method,
        public readonly string $status,
        public readonly Money $amount,
        public readonly string $createdAt,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        return new self(
            $payment->id,
            $payment->order_id,
            $payment->gateway,
            $payment->method,
            $payment->status->value,
            $payment->money,
            CarbonImmutable::instance($payment->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
