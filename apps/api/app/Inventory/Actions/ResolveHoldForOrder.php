<?php

namespace App\Inventory\Actions;

use App\Inventory\Data\HoldForOrderData;
use App\Inventory\Models\Hold;

/**
 * The read-only seam App\Orders\Actions\ConvertHoldToOrder calls to
 * load a hold's facts for conversion (stage-07 plan, Slice 1). Returns
 * null for a nonexistent or cross-tenant hold (RLS makes cross-tenant a
 * natural not-found); ownership and expiry policy belong to the caller.
 */
final class ResolveHoldForOrder
{
    public function __invoke(string $holdId): ?HoldForOrderData
    {
        $hold = Hold::query()->with('items')->find($holdId);

        return $hold === null ? null : HoldForOrderData::fromModel($hold);
    }
}
