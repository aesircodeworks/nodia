<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Models\Tenant;

/**
 * A cross-context read for Payments' method offer and initiation
 * (stage-08a plan, Endpoints): contexts never reach into another
 * context's Models directly (section 3.1 boundary rule), so the
 * enabled-gateway list ConfigureGateways maintains is exposed as an
 * Action, mirroring ResolveTenantSettlementCurrency.
 */
final class ResolveEnabledGateways
{
    /**
     * @return list<string>
     */
    public function __invoke(string $tenantId): array
    {
        return Tenant::query()->findOrFail($tenantId)->enabled_gateways ?? [];
    }
}
