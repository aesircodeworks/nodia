<?php

namespace App\Inventory\Actions;

use App\Inventory\Actions\Concerns\ReleasesHoldInventory;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Events\HoldReleased;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Inventory\Exceptions\HoldNotReleasableException;
use App\Inventory\Models\Hold;
use App\Support\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /v1/storefront/holds/{hold} (stage-06 plan, Slice 3, task
 * breakdown item 5; Endpoints "DELETE /v1/storefront/holds/{hold}").
 * Runs entirely inside the ambient request transaction
 * App\Tenancy\Http\Middleware\ResolveTenantFromHost already opened, so
 * the status transition, the counter release, and the HoldReleased
 * outbox row all commit or roll back together.
 *
 * The active -> released transition is a conditional UPDATE checked by
 * affected-row count, never a read-then-write (master plan test-first
 * rule 2): this is what guarantees exactly one of HoldReleased or
 * HoldExpired is ever recorded for a given hold when an explicit release
 * races App\Inventory\Actions\ReleaseExpiredHolds. A hold this call finds
 * already released or expired (whether by a prior call, a race with the
 * sweeper, or a race with another release request) is an idempotent
 * no-op: DELETE is idempotent by HTTP convention, and the sweeper or the
 * winning release already reconciled counters exactly once.
 */
final class ReleaseHold
{
    use ReleasesHoldInventory;

    public function __construct(private readonly OutboxRecorder $outbox) {}

    public function __invoke(string $holdId): void
    {
        $hold = Hold::query()->with('items')->find($holdId) ?? throw HoldNotFoundException::forId($holdId);

        if ($hold->status === HoldStatus::Committed) {
            throw HoldNotReleasableException::forId($holdId);
        }

        if ($hold->status !== HoldStatus::Active) {
            return;
        }

        $affected = DB::table('holds')
            ->where('id', $holdId)
            ->where('status', HoldStatus::Active->value)
            ->update(['status' => HoldStatus::Released->value, 'updated_at' => Date::now()]);

        if ($affected === 0) {
            $this->assertStillReleasable($holdId);

            return;
        }

        $this->releaseHeldInventory($hold);

        $this->outbox->record(HoldReleased::fromHold($hold));
    }

    /**
     * Reached only when this call's own conditional UPDATE lost a race
     * (the sweeper or another release request won it first). A committed
     * hold still refuses with hold_not_releasable; anything else (already
     * released or expired) is the same idempotent no-op as the early
     * return above.
     */
    private function assertStillReleasable(string $holdId): void
    {
        $current = Hold::query()->find($holdId) ?? throw HoldNotFoundException::forId($holdId);

        if ($current->status === HoldStatus::Committed) {
            throw HoldNotReleasableException::forId($holdId);
        }
    }
}
