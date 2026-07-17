<?php

namespace App\Payments\Events;

use App\Payments\Models\Payment;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for PaymentExpired (stage-08a plan, Domain
 * events). A distinct fact from PaymentFailed because the order machine
 * treats expired and failed as distinct terminal arcs (system-design
 * 7.1) and payload evolution is additive-only.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class PaymentExpiredPayload extends Data
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public string $gateway,
        public string $method,
    ) {}

    public static function fromPayment(Payment $payment): self
    {
        return new self($payment->id, $payment->order_id, $payment->gateway, $payment->method);
    }
}
