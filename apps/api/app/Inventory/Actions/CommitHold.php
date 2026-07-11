<?php

namespace App\Inventory\Actions;

use App\Inventory\Data\CommitHoldData;
use App\Inventory\Data\HoldData;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Exceptions\HoldNotCommittableException;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\Hold;
use App\Inventory\Models\HoldItem;
use App\Inventory\Models\TicketTypeInventory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The internal commit Action (stage-06 plan, Slice 4, task breakdown
 * item 8; system-design 7.1): converts a hold into sold inventory inside
 * the caller's own transaction (Stage 7's order-paid transaction). The
 * hold's own active -> committed transition is a conditional UPDATE
 * guarded by `status = 'active' AND expires_at > now()`, checked by
 * affected-row count (master plan test-first rule 2); this refuses a
 * hold the sweeper has not yet swept even though it is already past its
 * `expires_at`, refuses a hold already committed, and refuses a released
 * hold, all with the same typed exception. Each item's held -> sold move
 * is its own guarded conditional UPDATE, so a failure partway rolls the
 * whole commit back with the caller's transaction. No outbox event is
 * recorded here: there is no HoldCommitted event (stage-06 plan, exit
 * criteria 3), the commit is folded into whatever event the caller
 * records for its own state change (Stage 7's order-paid event).
 */
final class CommitHold
{
    public function __invoke(CommitHoldData $data): HoldData
    {
        $hold = Hold::query()->with('items')->find($data->holdId) ?? throw HoldNotFoundException::forId($data->holdId);

        $affected = DB::table('holds')
            ->where('id', $data->holdId)
            ->where('status', HoldStatus::Active->value)
            ->where('expires_at', '>', Date::now())
            ->update(['status' => HoldStatus::Committed->value, 'updated_at' => Date::now()]);

        if ($affected === 0) {
            throw HoldNotCommittableException::forId($data->holdId);
        }

        foreach ($hold->items as $item) {
            $this->commitItem($item);
        }

        $seatIds = $this->commitSeats($hold);

        return HoldData::fromModel($hold->fresh('items'), $seatIds);
    }

    /**
     * The seat side of a commit (stage-06 plan, event_seats section:
     * "commit moves held to sold per item and flips seats held -> sold").
     * Scoped by hold_id, which the earlier active -> committed conditional
     * UPDATE has already made exclusive to this call, so a plain
     * hold_id-guarded UPDATE is sufficient here, mirroring
     * App\Inventory\Actions\Concerns\ReleasesHoldInventory::
     * releaseHeldSeats's own posture.
     *
     * @return list<string>
     */
    private function commitSeats(Hold $hold): array
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
                    'status' => EventSeatStatus::Sold->value,
                    'updated_at' => Date::now(),
                ]);

            // The held -> sold flip must move exactly the seats just selected;
            // a lower count means a held seat slipped out from under this hold,
            // so the commit rolls back rather than sell fewer seats than the
            // hold covered (master plan test-first rule 2: conditional UPDATEs
            // are checked by affected-row count).
            if ($affected !== count($seatIds)) {
                throw HoldNotCommittableException::forId($hold->id);
            }
        }

        return $seatIds;
    }

    private function commitItem(HoldItem $item): void
    {
        $affected = TicketTypeInventory::query()
            ->where('ticket_type_id', $item->ticket_type_id)
            ->where('held', '>=', $item->quantity)
            ->update([
                'held' => DB::raw(sprintf('held - %d', $item->quantity)),
                'sold' => DB::raw(sprintf('sold + %d', $item->quantity)),
                'updated_at' => Date::now(),
            ]);

        if ($affected === 0) {
            throw HoldNotCommittableException::forId($item->hold_id);
        }
    }
}
