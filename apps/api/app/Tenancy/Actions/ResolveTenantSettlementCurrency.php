<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Models\Tenant;

/**
 * A cross-context read for EventCatalog's ticket type currency validation
 * (stage-05a plan, task breakdown item 8, slice 3: "a currency differing
 * from the tenant settlement currency returns catalog.currency_mismatch").
 * Contexts never reach into another context's Models directly (section
 * 3.1 boundary rule, tests/Architecture/ContextBoundariesTest), so this
 * one-line lookup is exposed as an Action instead of EventCatalog importing
 * App\Tenancy\Models\Tenant directly, mirroring
 * App\Tenancy\Actions\ResolveTenantDefaultLocale's own precedent for the
 * identical crossing.
 */
final class ResolveTenantSettlementCurrency
{
    public function __invoke(string $tenantId): string
    {
        return Tenant::query()->findOrFail($tenantId)->settlement_currency;
    }
}
