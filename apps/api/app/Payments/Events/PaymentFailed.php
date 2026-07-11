<?php

namespace App\Payments\Events;

use App\Payments\Models\Payment;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Payments\Actions\FailPayment in the same transaction
 * as the initiated to failed transition (stage-08a plan, Domain
 * events), for synchronous declines and failure webhooks alike.
 */
final readonly class PaymentFailed implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public PaymentFailedPayload $payload,
    ) {
        $this->aggregateType = 'payment';
    }

    public function type(): string
    {
        return 'PaymentFailed';
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

    public function payload(): PaymentFailedPayload
    {
        return $this->payload;
    }

    public static function fromPayment(Payment $payment): self
    {
        return new self($payment->tenant_id, $payment->id, PaymentFailedPayload::fromPayment($payment));
    }
}
