<?php

namespace App\Payments\Events;

use App\Payments\Models\Payment;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Payments\Actions\ConfirmPayment in the same
 * transaction as the initiated to confirmed transition (stage-08a plan,
 * Domain events), whether the confirmation arrived synchronously, by
 * webhook, or from the reconciliation poller.
 */
final readonly class PaymentConfirmed implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public PaymentConfirmedPayload $payload,
    ) {
        $this->aggregateType = 'payment';
    }

    public function type(): string
    {
        return 'PaymentConfirmed';
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

    public function payload(): PaymentConfirmedPayload
    {
        return $this->payload;
    }

    public static function fromPayment(Payment $payment): self
    {
        return new self($payment->tenant_id, $payment->id, PaymentConfirmedPayload::fromPayment($payment));
    }
}
