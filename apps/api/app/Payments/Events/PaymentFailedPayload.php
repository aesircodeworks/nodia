<?php

namespace App\Payments\Events;

use App\Payments\Models\Payment;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for PaymentFailed (stage-08a plan, Domain
 * events).
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class PaymentFailedPayload extends Data
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public string $gateway,
        public string $method,
        public string $failureCode,
    ) {}

    public static function fromPayment(Payment $payment): self
    {
        return new self(
            $payment->id,
            $payment->order_id,
            $payment->gateway,
            $payment->method,
            (string) $payment->failure_code,
        );
    }
}
