<?php

namespace App\Payments\Events;

use App\Payments\Models\Payment;
use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Internal outbox payload for PaymentConfirmed (stage-08a plan, Domain
 * events). Together with the fee_amount and commission_amount the
 * confirmation Action persists on the payment row, this payload is
 * deliberately sufficient for the Stage 8b ledger projection.
 */
#[Hidden]
#[MapName(SnakeCaseMapper::class)]
class PaymentConfirmedPayload extends Data
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public string $gateway,
        public string $method,
        public Money $amount,
        public Money $fee,
        public ?string $gatewayReference,
    ) {}

    public static function fromPayment(Payment $payment): self
    {
        return new self(
            $payment->id,
            $payment->order_id,
            $payment->gateway,
            $payment->method,
            $payment->money,
            Money::of($payment->fee_amount, $payment->currency),
            $payment->gateway_reference,
        );
    }
}
