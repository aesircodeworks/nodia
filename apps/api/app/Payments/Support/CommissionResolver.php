<?php

namespace App\Payments\Support;

use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use App\Tenancy\Actions\ResolveTenantCommissionConfig;

/**
 * Resolves the platform commission ConfirmPayment persists alongside the
 * gateway fee: basis points of gross from the tenant commission
 * configuration (stage-08b plan, slice 2), read through the Tenancy
 * Action at confirmation time and persisted as a row fact so ledger
 * replay stays deterministic when the configuration later changes.
 */
final class CommissionResolver
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResolveTenantCommissionConfig $commissionConfig,
        private readonly CommissionCalculator $calculator,
    ) {}

    public function resolve(Money $amount, Money $fee): Money
    {
        $config = ($this->commissionConfig)($this->tenantContext->tenantId());

        return $this->calculator->bpsOf($amount, $config['commission_bps']);
    }
}
