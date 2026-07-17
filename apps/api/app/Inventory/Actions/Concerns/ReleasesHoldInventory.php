<?php

namespace App\Inventory\Actions\Concerns;

use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Exceptions\HoldInventoryReleaseFailedException;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\Hold;
use App\Inventory\Models\HoldItem;
use App\Inventory\Models\TicketTypeInventory;
use App\Inventory\Support\PurchaseCounters;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Shared by App\Inventory\Actions\ReleaseHold and
 * App\Inventory\Actions\ReleaseExpiredHolds: the counter and seat
 * reconciliation half of a released or expired hold (stage-06 plan,
 * Domain events "counter and seat reconciliation amounts"; event_seats
 * section: "release and expiry decrement held per item and flip held
 * seats back to available"), run only after the caller's own conditional
 * active -> released/expired UPDATE has already affected exactly one
 * row, so this never double-decrements a hold whose transition another
 * process already won. Selecting this hold's own held seat ids before
 * flipping them is safe despite the read-then-write rule against bare
 * conditional guards: hold_id already scopes every row to the single
 * winner of that conditional UPDATE, so no other process can contend for
 * the same rows.
 *
 * The same posture extends to the stage-10 purchase counter: each item's
 * recorded counted_quantity (0 for a ticket type with no limit at hold
 * time) is reversed through App\Inventory\Support\PurchaseCounters::
 * decrement, which consults only that recorded amount, never the ticket
 * type's current max_per_customer (stage-10 plan, Data model
 * "purchase_counters").
 */
trait ReleasesHoldInventory
{
    /**
     * @return list<string> the event_seats ids returned to available
     */
    private function releaseHeldInventory(Hold $hold): array
    {
        foreach ($hold->items as $item) {
            $this->releaseHeldQuantity($item);
            $this->releasePurchaseCounter($hold, $item);
        }

        return $this->releaseHeldSeats($hold);
    }

    /**
     * @return list<string>
     */
    private function releaseHeldSeats(Hold $hold): array
    {
        $seatIds = EventSeat::query()
            ->where('hold_id', $hold->id)
            ->where('status', EventSeatStatus::Held->value)
            ->pluck('id')
            ->all();

        if ($seatIds !== []) {
            $affected = EventSeat::query()
                ->whereIn('id', $seatIds)
                ->where('hold_id', $hold->id)
                ->where('status', EventSeatStatus::Held->value)
                ->update([
                    'status' => EventSeatStatus::Available->value,
                    'hold_id' => null,
                    'hold_claim_position' => null,
                    'updated_at' => Date::now(),
                ]);

            // The conditional flip must move exactly the seats just selected;
            // a lower count means a held seat slipped out from under this hold
            // and the reconciliation is no longer sound (master plan test-first
            // rule 2: conditional UPDATEs are checked by affected-row count).
            if ($affected !== count($seatIds)) {
                throw HoldInventoryReleaseFailedException::forSeats($hold->id, count($seatIds), $affected);
            }
        }

        return $seatIds;
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
        $affected = TicketTypeInventory::query()
            ->where('ticket_type_id', $item->ticket_type_id)
            ->where('held', '>=', $item->quantity)
            ->update([
                'held' => DB::raw(sprintf('held - %d', $item->quantity)),
                'updated_at' => Date::now(),
            ]);

        // Zero affected rows means the counter is missing or holds fewer than
        // this item's units: a broken invariant, since an active hold's units
        // were counted into held at claim time. Rolling the whole release or
        // expiry back is the only safe outcome; recording HoldReleased or
        // HoldExpired here would leave inventory permanently inconsistent.
        if ($affected === 0) {
            throw HoldInventoryReleaseFailedException::forTicketType($item->ticket_type_id, $item->quantity);
        }
    }

    /**
     * The purchase-counter decrement (stage-10 plan, Data model
     * "purchase_counters"): a no-op for a zero counted_quantity (the item's
     * ticket type carried no max_per_customer at hold time, mirroring
     * App\Inventory\Support\PurchaseCounters::increment's own null-limit
     * skip). A nonzero counted_quantity always came from a successful
     * increment at hold creation, which requires a customer id
     * (App\Inventory\Exceptions\CustomerRequiredException), so the hold's
     * customer_id is guaranteed non-null here too; both branches surface
     * as the same broken-invariant exception as releaseHeldQuantity's own
     * zero-affected-rows case, rolling back the release or expiry
     * transaction rather than recording it against an inconsistent
     * counter.
     */
    private function releasePurchaseCounter(Hold $hold, HoldItem $item): void
    {
        if ($item->counted_quantity === 0) {
            return;
        }

        if ($hold->customer_id === null) {
            throw HoldInventoryReleaseFailedException::forPurchaseCounter($item->ticket_type_id, $item->counted_quantity);
        }

        $affected = PurchaseCounters::decrement($hold->customer_id, $item->ticket_type_id, $item->counted_quantity);

        if ($affected === 0) {
            throw HoldInventoryReleaseFailedException::forPurchaseCounter($item->ticket_type_id, $item->counted_quantity);
        }
    }
}
