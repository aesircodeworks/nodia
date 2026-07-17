<?php

namespace App\Support\Outbox;

use App\Support\Outbox\Enums\OutboxDeliveryStatus;
use App\Support\Outbox\Models\OutboxDelivery;
use App\Support\Outbox\Models\OutboxEvent;
use Illuminate\Support\Facades\Date;

/**
 * Per-aggregate readiness gate for ordered outbox consumers (system-design
 * 9.2, stage-04 plan Slice 4). An event is ready only when it is older than
 * the stability window (so a lower sequence for the same aggregate can no
 * longer commit after this one) and every same-aggregate lower-sequence
 * delivery for the same subscriber is processed. Different aggregates never
 * block each other. Call under the event's tenant scope so RLS applies.
 *
 * Ordering key defaults to the envelope aggregate; a
 * KeyedOrderedOutboxSubscriber supplies a payload field instead, so
 * events of different aggregates sharing that field's value (the ledger
 * projection's payment_id) form one ordered sequence. An event whose
 * payload lacks the field falls back to the envelope aggregate.
 */
final class OrderedConsumption
{
    /**
     * Whether an ordered subscriber may process this event now.
     */
    public function isReady(OutboxEvent $event, string $subscriber, ?string $keyPath = null): bool
    {
        if ($this->isYoungerThanStabilityWindow($event)) {
            return false;
        }

        if ($this->hasUnprocessedPredecessor($event, $subscriber, $keyPath)) {
            return false;
        }

        return true;
    }

    public function isYoungerThanStabilityWindow(OutboxEvent $event): bool
    {
        $cutoff = Date::now()->subSeconds(config()->integer('outbox.stability_window_seconds'));

        return $event->occurred_at->gt($cutoff);
    }

    /**
     * True when a lower-sequence delivery for the same ordering key and
     * subscriber is still pending. The envelope-aggregate path uses the
     * composite index on (aggregate_type, aggregate_id, sequence); the
     * payload-key path compares the jsonb field both sides carry.
     */
    public function hasUnprocessedPredecessor(OutboxEvent $event, string $subscriber, ?string $keyPath = null): bool
    {
        $key = $keyPath === null ? null : ($event->payload[$keyPath] ?? null);

        $query = OutboxDelivery::query()
            ->join('outbox_events', 'outbox_events.id', '=', 'outbox_deliveries.outbox_event_id')
            ->where('outbox_events.sequence', '<', $event->sequence)
            ->where('outbox_deliveries.subscriber', $subscriber)
            ->where('outbox_deliveries.status', OutboxDeliveryStatus::Pending);

        if (is_string($key)) {
            $query->whereRaw('outbox_events.payload ->> ? = ?', [$keyPath, $key]);
        } else {
            $query
                ->where('outbox_events.aggregate_type', $event->aggregate_type)
                ->where('outbox_events.aggregate_id', $event->aggregate_id);
        }

        return $query->exists();
    }
}
