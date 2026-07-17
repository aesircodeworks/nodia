<?php

namespace App\Payments\Events;

use App\Payments\Models\Payment;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Payments\Actions\ExpirePayment in the same
 * transaction as the initiated to expired transition (stage-08a plan,
 * Domain events), whether applied by the sweeper or by conversion-time
 * validation.
 */
final readonly class PaymentExpired implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public PaymentExpiredPayload $payload,
    ) {
        $this->aggregateType = 'payment';
    }

    public function type(): string
    {
        return 'PaymentExpired';
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

    public function payload(): PaymentExpiredPayload
    {
        return $this->payload;
    }

    public static function fromPayment(Payment $payment): self
    {
        return new self($payment->tenant_id, $payment->id, PaymentExpiredPayload::fromPayment($payment));
    }
}
