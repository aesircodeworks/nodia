<?php

namespace App\Inventory\Actions;

use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Inventory\Support\ReleaseStuckHoldsSummary;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The core of holds:release-stuck (stage-12 plan, Slice 5, task
 * breakdown item 13): a dry-run-by-default, --operator-required,
 * activity-logged manual release of holds stuck status active past
 * their expires_at, distinct from the automatic every-minute sweeper
 * (App\Console\Commands\ReleaseExpiredHoldsCommand /
 * App\Inventory\Actions\ReleaseExpiredHolds, stage-06) -- the same
 * "distinct verb from its automatic sibling" naming precedent
 * outbox:replay-failed and payments:reconcile-orders already took
 * against their own automatic siblings (see this task's journal entry).
 *
 * A committed hold past expires_at never appears: its inventory already
 * moved held to sold on the paid transition (system-design 7.1), so it
 * is not a release candidate; candidates() only ever selects status
 * active, the same filter App\Inventory\Actions\ReleaseExpiredHolds'
 * own findCandidates() uses.
 *
 * candidates() issues no writes at all: a dry-run investigation is safe
 * to run freely, unbounded. release() additionally releases each
 * matched hold through the Stage 6 App\Inventory\Actions\ReleaseHold
 * Action on $execute -- the exact same conditional-UPDATE counter
 * arithmetic the automatic sweeper and the DELETE endpoint both use,
 * reused rather than duplicated -- and always records one activity log
 * entry under the sentinel platform tenant naming the operator, the
 * arguments, and the matched/released counts, whether or not $execute
 * was set, mirroring OutboxFailedReplay and ReconcileNamedOrders's own
 * "a dry run is itself auditable" posture (stage-12 plan, task
 * breakdown item 6).
 *
 * ReleaseHold is already exactly-once safe against the sweeper on its
 * own (its conditional active -> released UPDATE admits one winner; see
 * tests/Concurrency/HoldExpiryRecoveryContentionTest.php), so release()
 * has no need to inspect who actually won a given hold to stay correct
 * under a race. "released" is computed by re-querying, after every
 * attempt has committed, how many of the matched holds now carry status
 * released; a hold the sweeper won the race for shows up expired there
 * instead, correctly excluded from this command's own count without
 * ReleaseHold (a void method) reporting anything about the race itself.
 */
final readonly class ReleaseStuckHolds
{
    public function __construct(
        private ReleaseHold $releaseHold,
        private TenantTransaction $transactions,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  list<string>  $tenantIds
     * @return Collection<int, Hold>
     */
    public function candidates(array $tenantIds = []): Collection
    {
        $now = Date::now();

        return $this->transactions->asPlatform(
            fn () => Hold::query()
                ->select('id', 'tenant_id', 'event_id', 'expires_at')
                ->where('status', HoldStatus::Active->value)
                ->where('expires_at', '<=', $now)
                ->when($tenantIds !== [], fn ($query) => $query->whereIn('tenant_id', $tenantIds))
                ->orderBy('expires_at')
                ->get(),
        );
    }

    /**
     * @param  list<string>  $tenantIds
     */
    public function release(string $operator, bool $execute, array $tenantIds): ReleaseStuckHoldsSummary
    {
        $holds = $this->candidates($tenantIds);

        if ($execute) {
            foreach ($holds as $hold) {
                $this->releaseOne($hold);
            }
        }

        $summary = new ReleaseStuckHoldsSummary($holds, $execute ? $this->countReleased($holds) : 0);

        $this->transactions->asPlatform(function () use ($operator, $execute, $tenantIds, $summary): void {
            $this->activityLogger->record(
                description: sprintf('holds:release-stuck %s by %s', $execute ? 'executed' : 'previewed', $operator),
                event: 'holds_release_stuck_invoked',
                properties: [
                    'operator' => $operator,
                    'execute' => $execute,
                    'tenant_ids' => $tenantIds,
                    'holds_matched' => $summary->holds->count(),
                    'holds_released' => $summary->released,
                ],
            );
        });

        return $summary;
    }

    private function releaseOne(Hold $hold): void
    {
        $this->transactions->asTenant(
            $hold->tenant_id,
            fn () => ($this->releaseHold)($hold->id),
        );
    }

    /**
     * @param  Collection<int, Hold>  $holds
     */
    private function countReleased(Collection $holds): int
    {
        if ($holds->isEmpty()) {
            return 0;
        }

        return $holds->groupBy('tenant_id')->sum(
            fn (Collection $group, string $tenantId): int => $this->transactions->asTenant(
                $tenantId,
                fn () => Hold::query()
                    ->whereIn('id', $group->pluck('id'))
                    ->where('status', HoldStatus::Released->value)
                    ->count(),
            ),
        );
    }
}
