<?php

namespace App\Inventory\Actions;

use App\Inventory\Models\Hold;

/**
 * Attaches the authenticated customer to a hold exactly once inside the
 * caller's conversion transaction (stage-07 plan, Scope: "SET
 * customer_id = :customer WHERE id = :hold AND (customer_id IS NULL OR
 * customer_id = :customer)"). Idempotent for the owner, a conditional
 * UPDATE checked by affected-row count for everyone else: two customers
 * racing one anonymous hold produce exactly one winner. Returns false
 * when the hold is missing or owned by someone else; the caller renders
 * both as hold_not_found so hold ids never leak ownership.
 */
final class AttachHoldCustomer
{
    public function __invoke(string $holdId, string $customerId): bool
    {
        return Hold::query()
            ->whereKey($holdId)
            ->where(function ($query) use ($customerId): void {
                $query->whereNull('customer_id')->orWhere('customer_id', $customerId);
            })
            ->update(['customer_id' => $customerId]) === 1;
    }
}
