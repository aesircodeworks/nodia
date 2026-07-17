<?php

namespace App\Support\Outbox;

use App\Support\Correlation\CorrelationId;
use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Records a domain event into outbox_events inside the producing database
 * transaction (system-design 9.1, event-conventions). Creates one pending
 * outbox_deliveries row per registered subscriber interested in the event
 * type in the same transaction, then schedules after-commit job enqueue
 * (system-design 9.2). Calling outside an open transaction is a
 * programming error.
 */
final readonly class OutboxRecorder
{
    public function __construct(
        private EventTypeRegistry $registry,
        private SubscriberRegistry $subscribers,
        private CorrelationId $correlationId,
        private OutboxDispatcher $dispatcher,
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

        $subscriberNames = $this->subscribers->namesFor($type);

        foreach ($subscriberNames as $subscriber) {
            OutboxDelivery::query()->create([
                'outbox_event_id' => $row->id,
                'tenant_id' => $envelope->tenantId,
                'subscriber' => $subscriber,
                'status' => OutboxDeliveryStatus::Pending,
            ]);
        }

        $this->dispatcher->dispatchAfterCommit($row->id, $envelope->tenantId, $subscriberNames);

        return $row;
    }
}
