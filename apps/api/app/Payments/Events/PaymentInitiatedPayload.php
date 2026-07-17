<?php

namespace App\Payments\Events;

use App\Payments\Models\Payment;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for PaymentInitiated (stage-08a plan, Domain
 * events): identifiers and facts only, no entity snapshot
 * (event-conventions). Hidden from TypeScript generation: event
 * payloads are not API contracts.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class PaymentInitiatedPayload extends Data
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public string $gateway,
        public string $method,
        public Money $amount,
        public ?string $expiresAt,
    ) {}

    public static function fromPayment(Payment $payment): self
    {
        return new self(
            $payment->id,
            $payment->order_id,
            $payment->gateway,
            $payment->method,
            $payment->money,
            $payment->expires_at === null ? null : CarbonImmutable::instance($payment->expires_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }
}
