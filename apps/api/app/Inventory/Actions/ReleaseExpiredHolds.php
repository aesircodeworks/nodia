<?php

namespace App\Inventory\Actions;

use App\Inventory\Actions\Concerns\ReleasesHoldInventory;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Events\HoldExpired;
use App\Inventory\Models\Hold;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The expiry sweeper (stage-06 plan, Slice 3, task breakdown item 6;
 * system-design 6.1 and 13). Scheduled every minute
 * (bootstrap/app.php); also guards against a lagging sweeper by design,
 * since conversion-time validation elsewhere in Inventory rejects an
 * expired hold regardless of whether this has run yet.
 *
 * Candidate discovery is a cross-tenant platform SELECT (mirrors
 * App\Support\Outbox\OutboxSweeper's own precedent), but the state
 * transition, counter release, and HoldExpired recording for each
 * candidate run inside that hold's own tenant transaction, one hold at a
 * time, so RLS still governs every write and a failure on one hold
 * cannot roll back another's.
 */
final readonly class ReleaseExpiredHolds
{
    use ReleasesHoldInventory;

    public function __construct(
        private TenantTransaction $transactions,
        private OutboxRecorder $outbox,
    ) {}

    public function __invoke(): int
    {
        $expired = 0;

        foreach ($this->findCandidates() as $candidate) {
            if ($this->expireOne($candidate->tenant_id, $candidate->id)) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * @return Collection<int, Hold>
     */
    private function findCandidates(): Collection
    {
        $now = Date::now();

        return $this->transactions->asPlatform(
            fn () => Hold::query()
                ->select('id', 'tenant_id')
                ->where('status', HoldStatus::Active->value)
                ->where('expires_at', '<=', $now)
                ->get(),
        );
    }

    /**
     * The active -> expired transition is a conditional UPDATE checked by
     * affected-row count (master plan test-first rule 2), the other half
     * of the exactly-once guarantee App\Inventory\Actions\ReleaseHold's
     * own docblock describes: a hold an explicit release already claimed
     * makes this UPDATE affect zero rows, so this hold is simply skipped,
     * never double-reconciled and never double-recorded.
     */
    private function expireOne(string $tenantId, string $holdId): bool
    {
        return $this->transactions->asTenant($tenantId, function () use ($holdId): bool {
            $affected = DB::table('holds')
                ->where('id', $holdId)
                ->where('status', HoldStatus::Active->value)
                ->update(['status' => HoldStatus::Expired->value, 'updated_at' => Date::now()]);

            if ($affected === 0) {
                return false;
            }

            $hold = Hold::query()->with('items')->findOrFail($holdId);

            $this->releaseHeldInventory($hold);

            $this->outbox->record(HoldExpired::fromHold($hold));

            return true;
        });
    }
}
