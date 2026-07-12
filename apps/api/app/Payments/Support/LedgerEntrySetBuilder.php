<?php

namespace App\Payments\Support;

use App\Payments\Enums\LedgerAccount;
use App\Payments\Enums\LedgerDirection;
use App\Payments\Enums\RefundCommissionPolicy;
use App\Payments\Exceptions\UnbalancedLedgerEntrySetException;
use App\Support\Money\Money;

/**
 * Builds the balanced leg sets of system-design 7.3. Zero-amount legs
 * are omitted so the amount > 0 check constraint holds; the sum of
 * debits still equals the sum of credits because every omitted leg is
 * exactly zero. Currency mismatches surface through Money's own
 * same-currency arithmetic guard.
 */
final class LedgerEntrySetBuilder
{
    /**
     * Debit gateway_receivable G; credit gateway_fees F, platform_commission C,
     * tenant_net N where N = G - F - C.
     *
     * @return list<LedgerLeg>
     */
    public function paymentLegs(Money $gross, Money $fee, Money $commission): array
    {
        $net = $gross->subtract($fee)->subtract($commission);

        if ($net->isNegative()) {
            throw UnbalancedLedgerEntrySetException::forPayment($gross->amount, $fee->amount, $commission->amount);
        }

        return $this->withoutZeroLegs([
            new LedgerLeg(LedgerAccount::GatewayReceivable, LedgerDirection::Debit, $gross),
            new LedgerLeg(LedgerAccount::GatewayFees, LedgerDirection::Credit, $fee),
            new LedgerLeg(LedgerAccount::PlatformCommission, LedgerDirection::Credit, $commission),
            new LedgerLeg(LedgerAccount::TenantNet, LedgerDirection::Credit, $net),
        ]);
    }

    /**
     * Credit gateway_receivable R; debit tenant_net R - Rc and
     * platform_commission Rc when the policy returns the commission,
     * debit tenant_net R alone when it is retained.
     *
     * @return list<LedgerLeg>
     */
    public function refundLegs(Money $amount, Money $returnedCommission, RefundCommissionPolicy $policy): array
    {
        if ($policy === RefundCommissionPolicy::Retained) {
            $returnedCommission = Money::of(0, $amount->currency);
        }

        $tenantDebit = $amount->subtract($returnedCommission);

        if ($tenantDebit->isNegative()) {
            throw UnbalancedLedgerEntrySetException::forRefund($amount->amount, $returnedCommission->amount);
        }

        return $this->withoutZeroLegs([
            new LedgerLeg(LedgerAccount::GatewayReceivable, LedgerDirection::Credit, $amount),
            new LedgerLeg(LedgerAccount::TenantNet, LedgerDirection::Debit, $tenantDebit),
            new LedgerLeg(LedgerAccount::PlatformCommission, LedgerDirection::Debit, $returnedCommission),
        ]);
    }

    /**
     * Debit tenant_net N; credit gateway_receivable N (system-design 7.3,
     * stage-08c plan Slice 6): the gateway's holding decreases by exactly
     * what it pays the tenant, so the pair always balances and never
     * needs the zero-leg omission the payment and refund sets do.
     *
     * @return list<LedgerLeg>
     */
    public function payoutLegs(Money $amount): array
    {
        return [
            new LedgerLeg(LedgerAccount::TenantNet, LedgerDirection::Debit, $amount),
            new LedgerLeg(LedgerAccount::GatewayReceivable, LedgerDirection::Credit, $amount),
        ];
    }

    /**
     * @param  list<LedgerLeg>  $legs
     * @return list<LedgerLeg>
     */
    private function withoutZeroLegs(array $legs): array
    {
        return array_values(array_filter($legs, fn (LedgerLeg $leg) => $leg->amount->amount !== 0));
    }
}
