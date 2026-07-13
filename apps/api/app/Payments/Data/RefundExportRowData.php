<?php

namespace App\Payments\Data;

use App\Payments\Models\Refund;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * One refund's export facts (stage-12 plan, Slice 2; task breakdown
 * item 6: the data subject export assembler's `refunds` category).
 * Plain internal class, never serialized to the wire, mirroring
 * App\Payments\Data\PaymentExportRowData's own posture.
 */
final class RefundExportRowData
{
    public function __construct(
        public readonly string $id,
        public readonly string $paymentId,
        public readonly string $status,
        public readonly Money $amount,
        public readonly ?string $reason,
        public readonly string $createdAt,
    ) {}

    public static function fromModel(Refund $refund): self
    {
        return new self(
            $refund->id,
            $refund->payment_id,
            $refund->status->value,
            $refund->money,
            $refund->reason,
            CarbonImmutable::instance($refund->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
