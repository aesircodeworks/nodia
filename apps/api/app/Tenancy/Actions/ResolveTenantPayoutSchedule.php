<?php

namespace App\Tenancy\Actions;

use App\Tenancy\Models\Tenant;

/**
 * A cross-context read for Payments' onboarding start (stage-08c plan,
 * Endpoints: "calls GatewayAdapter::createSubmerchant with the tenant's
 * settlement details and payout_schedule"). Contexts never reach into
 * another context's Models directly (section 3.1 boundary rule), so the
 * schedule Tenancy owns is exposed as an Action, mirroring
 * ResolveTenantSettlementCurrency and ResolveEnabledGateways.
 */
final class ResolveTenantPayoutSchedule
{
    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(string $tenantId): ?array
    {
        return Tenant::query()->findOrFail($tenantId)->payout_schedule;
    }
}
