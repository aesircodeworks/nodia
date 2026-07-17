<?php

namespace App\Payments\Data;

use App\Payments\Enums\RefundStatus;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The refund wire shape (stage-08b plan, Endpoints). order_id comes from
 * the owning payment row so clients can correlate without a second
 * lookup.
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class RefundData extends Data
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public string $orderId,
        public RefundStatus $status,
        public Money $amount,
        public Money $commissionAmount,
        public ?string $reason,
        public ?string $gatewayReference,
        public ?string $failureCode,
        public string $createdAt,
    ) {}

    public static function fromModel(Refund $refund, ?string $orderId = null): self
    {
        $orderId ??= $refund->getAttribute('order_id')
            ?? Payment::query()->findOrFail($refund->payment_id)->order_id;

        return new self(
            $refund->id,
            $refund->payment_id,
            $orderId,
            $refund->status,
            $refund->money,
            Money::of($refund->commission_amount, $refund->currency),
            $refund->reason,
            $refund->gateway_reference,
            $refund->failure_code,
            CarbonImmutable::instance($refund->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
