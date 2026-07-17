<?php

namespace App\Payments\Support;

use App\Support\Money\Money;

/**
 * Basis-points commission math on integer minor units. Rounding is
 * pinned to half up (stage-08b plan, Risks: "commission in basis points
 * forces a rounding rule; the plan pins round half up"); a statutory
 * rule from the launch market ADR overrides it before real money flows.
 */
final class CommissionCalculator
{
    public function bpsOf(Money $gross, int $bps): Money
    {
        $scaled = $gross->amount * $bps;
        $commission = intdiv($scaled, 10_000);

        if (($scaled % 10_000) * 2 >= 10_000) {
            $commission++;
        }

        return Money::of($commission, $gross->currency);
    }
}
