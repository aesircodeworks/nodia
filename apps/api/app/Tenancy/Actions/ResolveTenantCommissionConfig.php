<?php

namespace App\Tenancy\Actions;

use App\Payments\Enums\RefundCommissionPolicy;
use App\Tenancy\Models\Tenant;

/**
 * A cross-context read for Payments' commission resolution and refund
 * completion (stage-08b plan, Data model "tenants alterations"):
 * contexts never reach into another context's Models directly (section
 * 3.1 boundary rule), so the commission configuration is exposed as an
 * Action, mirroring ResolveEnabledGateways.
 */
final class ResolveTenantCommissionConfig
{
    /**
     * @return array{commission_bps: int, refund_commission_policy: RefundCommissionPolicy}
     */
    public function __invoke(string $tenantId): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        return [
            'commission_bps' => $tenant->commission_bps,
            'refund_commission_policy' => $tenant->refund_commission_policy,
        ];
    }
}
