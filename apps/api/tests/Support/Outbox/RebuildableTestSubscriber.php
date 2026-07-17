<?php

declare(strict_types=1);

namespace Tests\Support\Outbox;

use App\Reporting\Support\Rebuild\RebuildableProjection;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use App\Support\Outbox\ProjectionLockedSubscriber;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Test-only RebuildableProjection for stage-11 plan task 13 coverage
 * (App\Reporting\Support\Rebuild\ReportingProjectionRebuilder). Backs
 * only the Unit test that exercises
 * ReportingProjectionRebuilder::eventsFor(), which resolves the
 * registered subscriber and delegates straight to
 * OutboxReplay::eventsFor() without ever invoking handle(),
 * modelClass(), fold(), or rowFromModel(); those exist here only to
 * satisfy the interfaces. Registers only in test setup via
 * SubscriberRegistry and never enters production routing, mirroring
 * IdempotentTestSubscriber's own posture.
 */
final class RebuildableTestSubscriber implements OutboxSubscriber, ProjectionLockedSubscriber, RebuildableProjection
{
    public const string NAME = 'rebuildable_test';

    public function handle(OutboxEvent $event): void
    {
        // Unused by the eventsFor()-only coverage this fixture backs.
    }

    public function projectionLockKey(): string
    {
        return self::NAME;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function modelClass(): string
    {
        throw new LogicException('RebuildableTestSubscriber backs eventsFor() coverage only; it has no real projection table.');
    }

    public function keyColumns(): array
    {
        return ['aggregate_id'];
    }

    public function computeIncrement(OutboxEvent $event): ?object
    {
        return (object) ['aggregateId' => $event->aggregate_id];
    }

    public function keyFor(object $increment): array
    {
        return ['aggregate_id' => $increment->aggregateId];
    }

    public function fold(?array $row, object $increment): array
    {
        return $row ?? [];
    }

    public function rowFromModel(Model $model): array
    {
        return [];
    }
}
