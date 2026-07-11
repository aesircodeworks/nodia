<?php

namespace App\Payments\Support;

use App\Support\Money\Money;

/**
 * Resolves the platform commission ConfirmPayment persists alongside the
 * gateway fee. Returns zero until Stage 8b lands the tenant commission
 * configuration and wires the rate in here (stage-08a plan, Data model
 * "payments"), so the confirm transition and the PaymentConfirmed
 * payload are already ledger-sufficient.
 */
final class CommissionResolver
{
    public function resolve(Money $amount, Money $fee): Money
    {
        return Money::of(0, $amount->currency);
    }
}
