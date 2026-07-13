<?php

namespace App\Reporting\Support\Rebuild;

use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxReplay;
use App\Support\Outbox\OutboxSubscriber;
use App\Support\Outbox\SubscriberRegistry;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Rebuilds or verifies one reporting projection from the Stage 4 replay
 * primitive (stage-11 plan, task 13, wrapping App\Support\Outbox\
 * OutboxReplay). eventsFor() delegates sequencing and the stability
 * window entirely to OutboxReplay::eventsFor(): this class never
 * re-sorts or re-filters events itself.
 *
 * rebuild() deletes the projection's rows for the given tenant (or every
 * tenant the eligible event stream touches) and replays them through the
 * registered OutboxSubscriber's own handle(), one tenant at a time
 * inside that tenant's own asTenant() transaction (delete and replay
 * commit together; RLS requires the app role's own tenant-scoped
 * transaction for both the delete and the write, so one transaction per
 * tenant is the natural atomic unit, not one transaction spanning every
 * tenant). Delivery rows are never consulted or mutated, mirroring
 * OutboxReplay::replay()'s own documented posture.
 *
 * verify() never writes: it folds the same events into an
 * InMemoryProjectionAggregate per tenant and diffs it against a live
 * SELECT of that tenant's rows.
 */
final readonly class ReportingProjectionRebuilder
{
    public function __construct(
        private OutboxReplay $replay,
        private SubscriberRegistry $subscribers,
        private TenantTransaction $transactions,
    ) {}

    /**
     * @return int Number of events replayed
     */
    public function rebuild(string $projectionName, ?string $tenantId): int
    {
        [$handler, $events] = $this->prepare($projectionName);

        $tenantIds = $tenantId !== null ? [$tenantId] : self::distinctTenantIds($events);

        $applied = 0;

        foreach ($tenantIds as $id) {
            $tenantEvents = self::eventsForTenant($events, $id);

            $applied += $this->transactions->asTenant($id, function () use ($handler, $tenantEvents): int {
                $handler->modelClass()::query()->delete();

                foreach ($tenantEvents as $event) {
                    $handler->handle($event);
                }

                return $tenantEvents->count();
            });
        }

        return $applied;
    }

    public function verify(string $projectionName, ?string $tenantId): ProjectionDrift
    {
        [$handler, $events] = $this->prepare($projectionName);

        $tenantIds = $tenantId !== null ? [$tenantId] : self::distinctTenantIds($events);

        $missing = 0;
        $extra = 0;
        $mismatched = 0;

        foreach ($tenantIds as $id) {
            $aggregate = new InMemoryProjectionAggregate($handler);

            foreach (self::eventsForTenant($events, $id) as $event) {
                $aggregate->apply($event);
            }

            $liveRows = $this->transactions->asTenant(
                $id,
                fn (): Collection => $handler->modelClass()::query()->get(),
            );

            $liveByToken = [];

            foreach ($liveRows as $model) {
                $key = Arr::only($model->getAttributes(), $handler->keyColumns());
                $liveByToken[InMemoryProjectionAggregate::token($key)] = $handler->rowFromModel($model);
            }

            $seen = [];

            foreach ($aggregate->entries() as $token => $entry) {
                $seen[$token] = true;

                if (! array_key_exists($token, $liveByToken)) {
                    $missing++;

                    continue;
                }

                if ($liveByToken[$token] !== $entry['row']) {
                    $mismatched++;
                }
            }

            foreach ($liveByToken as $token => $row) {
                if (! isset($seen[$token])) {
                    $extra++;
                }
            }
        }

        return new ProjectionDrift($missing, $extra, $mismatched);
    }

    /**
     * The projection's eligible event stream, in the exact order and
     * filtering OutboxReplay::eventsFor() itself produces (sequence
     * ascending, past the stability window): this method re-sorts or
     * re-filters nothing, it only resolves which types belong to the
     * named projection before delegating (stage-11 plan, task 13 Unit
     * test: "rebuild reads in sequence order through the replay
     * primitive and respects the stability window").
     *
     * @return Collection<int, OutboxEvent>
     */
    public function eventsFor(string $projectionName): Collection
    {
        return $this->prepare($projectionName)[1];
    }

    /**
     * @return array{0: OutboxSubscriber&RebuildableProjection, 1: Collection<int, OutboxEvent>}
     */
    private function prepare(string $projectionName): array
    {
        $handler = $this->subscribers->handler($projectionName);

        if (! $handler instanceof RebuildableProjection) {
            throw new LogicException("Subscriber [{$projectionName}] is not a rebuildable reporting projection.");
        }

        $types = $this->subscribers->typesFor($projectionName);

        return [$handler, $this->replay->eventsFor($types)];
    }

    /**
     * @param  Collection<int, OutboxEvent>  $events
     * @return Collection<int, OutboxEvent>
     */
    private static function eventsForTenant(Collection $events, string $tenantId): Collection
    {
        return $events->filter(fn (OutboxEvent $event): bool => $event->tenant_id === $tenantId)->values();
    }

    /**
     * @param  Collection<int, OutboxEvent>  $events
     * @return list<string>
     */
    private static function distinctTenantIds(Collection $events): array
    {
        return $events->pluck('tenant_id')->unique()->values()->all();
    }
}
