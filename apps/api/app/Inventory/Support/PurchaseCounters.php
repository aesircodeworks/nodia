<?php

namespace App\Inventory\Support;

use App\Inventory\Models\PurchaseCounter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The two guarded statements App\Inventory\Actions\CreateHold and the
 * release/expiry path enforce `max_per_customer` with (stage-10 plan,
 * Data model "purchase_counters"), each a conditional statement checked
 * by affected-row count, never a read-then-write existence check (master
 * plan test-first rule 2). Both are pure internal operations: neither
 * throws on the guard failing, leaving that decision (rolling back the
 * hold transaction, raising a domain exception) to the caller.
 */
final class PurchaseCounters
{
    /**
     * `INSERT ... ON CONFLICT (customer_id, ticket_type_id) DO UPDATE SET
     * quantity = purchase_counters.quantity + :n WHERE
     * purchase_counters.quantity + :n <= :limit` (stage-10 plan, Data
     * model "purchase_counters"). Zero affected rows means the limit is
     * exceeded: the conflicting row is left untouched. A null $limit
     * means the ticket type carries no purchase limit at hold time, so
     * the counter is skipped entirely, no row is inserted or updated, and
     * the increment always succeeds.
     */
    public static function increment(
        string $tenantId,
        string $customerId,
        string $ticketTypeId,
        int $quantity,
        ?int $limit,
    ): bool {
        if ($limit === null) {
            return true;
        }

        $now = Date::now();

        $affected = DB::affectingStatement(
            <<<'SQL'
            insert into purchase_counters (id, tenant_id, customer_id, ticket_type_id, quantity, created_at, updated_at)
            values (?, ?, ?, ?, ?, ?, ?)
            on conflict (customer_id, ticket_type_id)
            do update set quantity = purchase_counters.quantity + excluded.quantity, updated_at = excluded.updated_at
            where purchase_counters.quantity + excluded.quantity <= ?
            SQL,
            [(string) Str::uuid7(), $tenantId, $customerId, $ticketTypeId, $quantity, $now, $now, $limit],
        );

        return $affected > 0;
    }

    /**
     * `UPDATE ... SET quantity = quantity - :counted WHERE customer_id =
     * :customer AND ticket_type_id = :type AND quantity >= :counted`
     * (stage-10 plan, Data model "purchase_counters"): reverses only the
     * amount recorded on the hold item at claim time, never the ticket
     * type's current policy, so a `max_per_customer` changed or cleared
     * between hold creation and release can neither strand counted
     * quantity nor decrement quantity the hold never contributed. The
     * `quantity >= :counted` guard floors the counter at zero; the
     * purchase_counters_quantity_non_negative CHECK is defense in depth
     * behind it, never the primary guard. $counted of zero (an item whose
     * ticket type carried no limit at hold time) is a no-op, matching
     * increment's own skip for a null limit.
     */
    public static function decrement(string $customerId, string $ticketTypeId, int $counted): int
    {
        if ($counted === 0) {
            return 0;
        }

        return PurchaseCounter::query()
            ->where('customer_id', $customerId)
            ->where('ticket_type_id', $ticketTypeId)
            ->where('quantity', '>=', $counted)
            ->update([
                'quantity' => DB::raw(sprintf('quantity - %d', $counted)),
                'updated_at' => Date::now(),
            ]);
    }
}
