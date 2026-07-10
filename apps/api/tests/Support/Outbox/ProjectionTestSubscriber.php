<?php

declare(strict_types=1);

namespace Tests\Support\Outbox;

use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;

/**
 * Idempotent projection-style test subscriber for Slice 5 replay
 * coverage. State is commutative (keyed by event id and aggregate) so
 * unordered incremental delivery and sequence-order replay converge.
 * Registers only in test setup.
 */
final class ProjectionTestSubscriber implements OutboxSubscriber
{
    public const string NAME = 'projection_test';

    public const string REBUILD_NAME = 'projection_rebuild';

    /** @var array<string, true> */
    private array $appliedEventIds = [];

    /** @var array<string, int> */
    private array $countsByAggregate = [];

    /** @var list<int> */
    private array $visitOrder = [];

    public function handle(OutboxEvent $event): void
    {
        if (isset($this->appliedEventIds[$event->id])) {
            return;
        }

        $this->appliedEventIds[$event->id] = true;
        $this->visitOrder[] = (int) $event->sequence;
        $this->countsByAggregate[$event->aggregate_id] =
            ($this->countsByAggregate[$event->aggregate_id] ?? 0) + 1;
    }

    /**
     * Stable comparable projection state (order-independent).
     *
     * @return array{event_ids: list<string>, counts: array<string, int>}
     */
    public function snapshot(): array
    {
        $ids = array_keys($this->appliedEventIds);
        sort($ids);

        $counts = $this->countsByAggregate;
        ksort($counts);

        return [
            'event_ids' => $ids,
            'counts' => $counts,
        ];
    }

    /**
     * Sequences in the order this instance applied them (first visit only).
     *
     * @return list<int>
     */
    public function visitOrder(): array
    {
        return $this->visitOrder;
    }

    public function appliedCount(): int
    {
        return count($this->appliedEventIds);
    }
}
