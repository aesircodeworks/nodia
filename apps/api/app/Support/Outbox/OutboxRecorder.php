<?php

namespace App\Support\Outbox;

use App\Support\Correlation\CorrelationId;
use App\Support\Outbox\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Records a domain event into outbox_events inside the producing database
 * transaction (system-design 9.1, event-conventions). Calling outside an
 * open transaction is a programming error. Does not create deliveries;
 * that lands with the dispatcher (stage-04 plan, tasks 6/7).
 */
final readonly class OutboxRecorder
{
    public function __construct(
        private EventTypeRegistry $registry,
        private CorrelationId $correlationId,
    ) {}

    public function record(DomainEvent $event): OutboxEvent
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('OutboxRecorder::record() may only be called inside an open database transaction.');
        }

        $type = $event->type();

        if (! $this->registry->contains($type)) {
            throw new LogicException("Event type [{$type}] is not registered.");
        }

        $envelope = new OutboxEnvelope(
            id: Str::uuid7()->toString(),
            type: $type,
            tenantId: $event->tenantId(),
            aggregateType: $event->aggregateType(),
            aggregateId: $event->aggregateId(),
            correlationId: $this->correlationId->get(),
            occurredAt: now(),
            payload: $event->payload()->toArray(),
        );

        $row = OutboxEvent::query()->create([
            'id' => $envelope->id,
            'type' => $envelope->type,
            'tenant_id' => $envelope->tenantId,
            'aggregate_type' => $envelope->aggregateType,
            'aggregate_id' => $envelope->aggregateId,
            'correlation_id' => $envelope->correlationId,
            'occurred_at' => $envelope->occurredAt,
            'payload' => $envelope->payload,
        ]);

        // sequence is a generated identity column; refresh so the returned
        // model carries the database-assigned value (not present on insert).
        $row->refresh();

        return $row;
    }
}
