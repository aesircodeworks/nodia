<?php

namespace App\Orders\Events;

use App\Orders\Models\Order;
use App\Support\Outbox\DomainEvent;

/**
 * Recorded by App\Orders\Actions\ConvertHoldToOrder in the producing
 * transaction, exactly once, when a hold becomes an order (stage-07
 * plan, Domain events).
 */
final readonly class OrderCreated implements DomainEvent
{
    public string $aggregateType;

    public function __construct(
        public string $tenantId,
        public string $aggregateId,
        public OrderCreatedPayload $payload,
    ) {
        $this->aggregateType = 'order';
    }

    public function type(): string
    {
        return 'OrderCreated';
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

    public function payload(): OrderCreatedPayload
    {
        return $this->payload;
    }

    public static function fromOrder(Order $order): self
    {
        return new self($order->tenant_id, $order->id, OrderCreatedPayload::fromOrder($order));
    }
}
