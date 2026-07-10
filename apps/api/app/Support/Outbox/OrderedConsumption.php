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
 * Ordering key is the envelope aggregate; Stage 8b extends this helper with
 * a subscriber-supplied payload-derived key for cross-aggregate sequences.
 */
final class OrderedConsumption
{
    /**
     * Whether an ordered subscriber may process this event now.
     */
    public function isReady(OutboxEvent $event, string $subscriber): bool
    {
        if ($this->isYoungerThanStabilityWindow($event)) {
            return false;
        }

        if ($this->hasUnprocessedPredecessor($event, $subscriber)) {
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
     * True when a lower-sequence delivery for the same aggregate and
     * subscriber is still pending. Uses the composite index on
     * (aggregate_type, aggregate_id, sequence).
     */
    public function hasUnprocessedPredecessor(OutboxEvent $event, string $subscriber): bool
    {
        return OutboxDelivery::query()
            ->join('outbox_events', 'outbox_events.id', '=', 'outbox_deliveries.outbox_event_id')
            ->where('outbox_events.aggregate_type', $event->aggregate_type)
            ->where('outbox_events.aggregate_id', $event->aggregate_id)
            ->where('outbox_events.sequence', '<', $event->sequence)
            ->where('outbox_deliveries.subscriber', $subscriber)
            ->where('outbox_deliveries.status', OutboxDeliveryStatus::Pending)
            ->exists();
    }
}
