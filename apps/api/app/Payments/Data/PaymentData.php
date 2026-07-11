<?php

namespace App\Payments\Data;

use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The payment wire shape (stage-08a plan, Endpoints). next_action is
 * persisted at initiation so replays and the polling endpoint re-serve
 * it byte-identically, e.g. the Pix code.
 */
#[TypeScript]
#[MapName(SnakeCaseMapper::class)]
class PaymentData extends Data
{
    public function __construct(
        public string $id,
        public string $orderId,
        public PaymentStatus $status,
        public string $gateway,
        public string $method,
        public Money $amount,
        public ?string $expiresAt,
        public NextActionData $nextAction,
        public ?string $failureCode,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        return new self(
            $payment->id,
            $payment->order_id,
            $payment->status,
            $payment->gateway,
            $payment->method,
            $payment->money,
            $payment->expires_at === null ? null : CarbonImmutable::instance($payment->expires_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            $payment->next_action === null ? NextActionData::none() : NextActionData::from($payment->next_action),
            $payment->failure_code,
        );
    }
}
