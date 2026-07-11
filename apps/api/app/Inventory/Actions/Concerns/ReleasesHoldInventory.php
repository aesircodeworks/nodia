<?php

namespace App\Inventory\Actions\Concerns;

use App\Inventory\Models\Hold;
use App\Inventory\Models\HoldItem;
use App\Inventory\Models\TicketTypeInventory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Shared by App\Inventory\Actions\ReleaseHold and
 * App\Inventory\Actions\ReleaseExpiredHolds: the counter-reconciliation
 * half of a released or expired hold (stage-06 plan, Domain events
 * "counter and seat reconciliation amounts"), run only after the caller's
 * own conditional active -> released/expired UPDATE has already affected
 * exactly one row, so this never double-decrements a hold whose
 * transition another process already won.
 */
trait ReleasesHoldInventory
{
    private function releaseHeldInventory(Hold $hold): void
    {
        foreach ($hold->items as $item) {
            $this->releaseHeldQuantity($item);
        }
    }

    /**
     * The held-decrement guard, the mirror image of CreateHold::claim's
     * held-increment one: `UPDATE ticket_type_inventory SET held = held -
     * :n WHERE ticket_type_id = :id AND held >= :n`, a conditional UPDATE
     * checked by affected-row count, never a read-then-write (master plan
     * test-first rule 2). held >= n also guards against a stray double
     * release ever driving the counter negative.
     */
    private function releaseHeldQuantity(HoldItem $item): void
    {
        TicketTypeInventory::query()
            ->where('ticket_type_id', $item->ticket_type_id)
            ->where('held', '>=', $item->quantity)
            ->update([
                'held' => DB::raw(sprintf('held - %d', $item->quantity)),
                'updated_at' => Date::now(),
            ]);
    }
}
