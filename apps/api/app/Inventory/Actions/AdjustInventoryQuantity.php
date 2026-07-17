<?php

namespace App\Inventory\Actions;

use App\Inventory\Exceptions\InsufficientInventoryException;
use App\Inventory\Models\TicketTypeInventory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Changes a ticket type's `quantity` by `$delta` (stage-06 plan, task
 * breakdown item 2; Data model "ticket_type_inventory": "Quantity
 * decreases use the same pattern (`quantity >= sold + held` after the
 * change); increases are unconditional."). An increase can never break
 * the `sold + held <= quantity` invariant, so it runs as a plain UPDATE;
 * a decrease is a conditional UPDATE re-checking the invariant against
 * the post-change quantity in the same statement, checked by
 * affected-row count, never a read-then-write existence check (master
 * plan test-first rule 2; CLAUDE.md). Zero affected rows means the
 * decrease would oversell what is already sold or held.
 */
final class AdjustInventoryQuantity
{
    public function __invoke(string $ticketTypeId, int $delta): void
    {
        $now = Date::now();

        $query = TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId);

        if ($delta < 0) {
            $query->whereRaw('sold + held <= quantity + ?', [$delta]);
        }

        $affected = $query->update([
            'quantity' => DB::raw(sprintf('quantity + (%d)', $delta)),
            'updated_at' => $now,
        ]);

        if ($affected === 0) {
            throw InsufficientInventoryException::forTicketType($ticketTypeId);
        }
    }
}
