<?php

namespace App\Inventory\Actions;

use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use Illuminate\Support\Facades\Date;

/**
 * Attaches the authenticated customer to a hold exactly once inside the
 * caller's conversion transaction (stage-07 plan, Scope: "SET
 * customer_id = :customer WHERE id = :hold AND (customer_id IS NULL OR
 * customer_id = :customer)"). Idempotent for the owner, a conditional
 * UPDATE checked by affected-row count for everyone else: two customers
 * racing one anonymous hold produce exactly one winner. The UPDATE also
 * guards hold liveness (active and unexpired) so a sweeper or release
 * racing in after the caller's own checks cannot let an invalid hold
 * convert. Returns false when the hold is missing, dead, or owned by
 * someone else; the caller re-reads to map that onto hold_not_found or
 * checkout.hold_expired without leaking ownership.
 */
final class AttachHoldCustomer
{
    public function __invoke(string $holdId, string $customerId): bool
    {
        return Hold::query()
            ->whereKey($holdId)
            ->where('status', HoldStatus::Active)
            ->where('expires_at', '>', Date::now())
            ->where(function ($query) use ($customerId): void {
                $query->whereNull('customer_id')->orWhere('customer_id', $customerId);
            })
            ->update(['customer_id' => $customerId]) === 1;
    }
}
