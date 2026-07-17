<?php

namespace App\Reporting\Support;

use App\Support\Money\Money;

/**
 * The commutative increment one PaymentConfirmed or RefundCompleted
 * event contributes to one report_event_finance row (stage-11 plan,
 * Domain events "Consumed" table; task 8). Every field here is either a
 * recorded fact carried straight through (gross from the
 * PaymentConfirmed payload, fee and commission from the payment row via
 * App\Payments\Actions\GetPaymentEventFinanceFacts, the refund amount
 * and its already-policy-resolved commission from the RefundCompleted
 * payload) or plain addition/subtraction of those facts; this class
 * performs no commission arithmetic of its own (no bps resolution, no
 * tenant configuration lookup).
 *
 * gross_amount, platform_commission_amount, and tenant_net_amount are
 * all netted by refunds (see the money semantics note on the
 * report_event_finance migration and App\Reporting\Models\EventFinance):
 * every increment below satisfies, by construction,
 * deltaNet = deltaGross - deltaFee - deltaCommission, so summing any
 * sequence of payment and refund increments in any order keeps
 * gross_amount - gateway_fee_amount - platform_commission_amount =
 * tenant_net_amount true (stage-11 plan, TDD sequencing Slice 3 Unit
 * test; exit criterion 5). gateway_fee_amount is untouched by refunds,
 * since the gateway never returns its fee, mirroring
 * LedgerEntrySetBuilder::refundLegs, which never touches the
 * gateway_fees account either. refunded_amount is a second, separate
 * face-value counter, never netted against gross_amount, mirroring
 * report_daily_sales' own dual gross/refunded posture.
 */
final readonly class EventFinanceIncrement
{
    private function __construct(
        public string $eventId,
        public int $ordersPaidCount,
        public int $refundsCount,
        public int $grossAmount,
        public int $gatewayFeeAmount,
        public int $platformCommissionAmount,
        public int $tenantNetAmount,
        public int $refundedAmount,
        public string $currency,
    ) {}

    /**
     * gross from the PaymentConfirmed payload; fee and commission from
     * the payment row facts; net as gross minus fee minus commission
     * (stage-11 plan, Data model "report_event_finance").
     */
    public static function paymentConfirmed(string $eventId, Money $gross, Money $fee, Money $commission): self
    {
        $net = $gross->amount - $fee->amount - $commission->amount;

        return new self($eventId, 1, 0, $gross->amount, $fee->amount, $commission->amount, $net, 0, $gross->currency);
    }

    /**
     * amount and returnedCommission are the RefundCompleted payload's
     * own amount and commission_amount facts (already policy-resolved
     * at refund creation, Stage 8b: zero under the retained policy,
     * proportional under the returned policy), never recomputed here.
     * gross and commission both decrease by the returned share; net
     * decreases by exactly the remainder, so the identity above holds
     * for both commission policies without this class inspecting the
     * policy at all.
     */
    public static function refundCompleted(string $eventId, Money $amount, Money $returnedCommission): self
    {
        $netDelta = -($amount->amount - $returnedCommission->amount);

        return new self($eventId, 0, 1, -$amount->amount, 0, -$returnedCommission->amount, $netDelta, $amount->amount, $amount->currency);
    }
}
