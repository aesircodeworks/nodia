<?php

namespace App\Inventory\Support;

use Illuminate\Http\Request;

/**
 * Key derivation for the `hold_creation` named rate limiter (stage-10
 * plan, Endpoints "Rate limiting tiers": "strict, per IP and, when
 * present, per customer"). Isolated from InventoryServiceProvider so the
 * derivation itself is unit-testable without booting the HTTP kernel.
 * Guest checkout means no authentication is required to create a hold
 * (App\Inventory\Http\Controllers\HoldController), so the customer key
 * is only ever present when the request carries a valid customer bearer
 * token, never derived from request body input.
 */
final class RateLimiterKeys
{
    public static function ip(Request $request): string
    {
        return (string) $request->ip();
    }

    public static function customer(Request $request): ?string
    {
        $id = $request->user('customer')?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
