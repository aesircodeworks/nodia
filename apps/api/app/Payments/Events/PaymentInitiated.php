<?php

namespace App\Payments\Events;

use App\Payments\Models\Payment;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Payments\Actions\InitiatePayment in the producing
 * transaction once the payment row exists and the adapter's
 * createPayment succeeded (stage-08a plan, Domain events).
 */
final readonly class PaymentInitiated implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public PaymentInitiatedPayload $payload,
    ) {
        $this->aggregateType = 'payment';
    }

    public function type(): string
    {
        return 'PaymentInitiated';
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function aggregateType(): string
    {
        return $this->aggregateType;
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function payload(): PaymentInitiatedPayload
    {
        return $this->payload;
    }

    public static function fromPayment(Payment $payment): self
    {
        return new self($payment->tenant_id, $payment->id, PaymentInitiatedPayload::fromPayment($payment));
    }
}
