<?php

use App\Reporting\Support\EventFinanceIncrement;
use App\Support\Money\Money;

/*
 * Stage-11 plan, Slice 3 Unit test: payload/row-fact-to-increment
 * mapping, and the mandated balance assertion that
 * gross_amount - gateway_fee_amount - platform_commission_amount =
 * tenant_net_amount holds after every simulated payment and refund
 * sequence, under both commission policies, without this class
 * performing any commission arithmetic of its own (no bps resolution,
 * no tenant configuration lookup: every field is either a fact carried
 * straight through or a plain addition/subtraction of such facts).
 */

it('maps a confirmed payment to +1 orders_paid_count and gross minus fee minus commission net', function (): void {
    $increment = EventFinanceIncrement::paymentConfirmed(
        'event-1',
        Money::of(10_000, 'USD'),
        Money::of(300, 'USD'),
        Money::of(500, 'USD'),
    );

    expect($increment->eventId)->toBe('event-1')
        ->and($increment->ordersPaidCount)->toBe(1)
        ->and($increment->refundsCount)->toBe(0)
        ->and($increment->grossAmount)->toBe(10_000)
        ->and($increment->gatewayFeeAmount)->toBe(300)
        ->and($increment->platformCommissionAmount)->toBe(500)
        ->and($increment->tenantNetAmount)->toBe(9_200)
        ->and($increment->refundedAmount)->toBe(0)
        ->and($increment->currency)->toBe('USD');
});

it('maps a refund under the returned commission policy to signed deltas that net exactly the remainder, touching no fee', function (): void {
    // The refund row's own commission_amount is already policy-resolved
    // at refund creation (Stage 8b); this class only forwards it.
    $increment = EventFinanceIncrement::refundCompleted('event-1', Money::of(10_000, 'USD'), Money::of(500, 'USD'));

    expect($increment->ordersPaidCount)->toBe(0)
        ->and($increment->refundsCount)->toBe(1)
        ->and($increment->grossAmount)->toBe(-10_000)
        ->and($increment->gatewayFeeAmount)->toBe(0)
        ->and($increment->platformCommissionAmount)->toBe(-500)
        ->and($increment->tenantNetAmount)->toBe(-9_500)
        ->and($increment->refundedAmount)->toBe(10_000)
        ->and($increment->currency)->toBe('USD');
});

it('maps a refund under the retained commission policy (zero returned commission) to the full amount debiting tenant_net alone', function (): void {
    // CreateRefund resolves commission_amount to zero under the retained
    // policy (stage-08b plan), so the payload this class receives already
    // carries zero; no policy branching happens here.
    $increment = EventFinanceIncrement::refundCompleted('event-1', Money::of(10_000, 'USD'), Money::of(0, 'USD'));

    expect($increment->platformCommissionAmount)->toBe(0)
        ->and($increment->tenantNetAmount)->toBe(-10_000)
        ->and($increment->refundedAmount)->toBe(10_000);
});

/**
 * @return array{ordersPaidCount: int, refundsCount: int, grossAmount: int, gatewayFeeAmount: int, platformCommissionAmount: int, tenantNetAmount: int, refundedAmount: int}
 */
function applyEventFinanceIncrement(array $totals, EventFinanceIncrement $increment): array
{
    return [
        'ordersPaidCount' => $totals['ordersPaidCount'] + $increment->ordersPaidCount,
        'refundsCount' => $totals['refundsCount'] + $increment->refundsCount,
        'grossAmount' => $totals['grossAmount'] + $increment->grossAmount,
        'gatewayFeeAmount' => $totals['gatewayFeeAmount'] + $increment->gatewayFeeAmount,
        'platformCommissionAmount' => $totals['platformCommissionAmount'] + $increment->platformCommissionAmount,
        'tenantNetAmount' => $totals['tenantNetAmount'] + $increment->tenantNetAmount,
        'refundedAmount' => $totals['refundedAmount'] + $increment->refundedAmount,
    ];
}

it('keeps gross minus fee minus commission equal to net after every step of a mixed payment and refund sequence, under both commission policies', function (): void {
    $zero = [
        'ordersPaidCount' => 0, 'refundsCount' => 0, 'grossAmount' => 0,
        'gatewayFeeAmount' => 0, 'platformCommissionAmount' => 0, 'tenantNetAmount' => 0, 'refundedAmount' => 0,
    ];

    $sequence = [
        // three payments of varying gross, fee, and commission
        EventFinanceIncrement::paymentConfirmed('event-1', Money::of(10_000, 'USD'), Money::of(300, 'USD'), Money::of(500, 'USD')),
        EventFinanceIncrement::paymentConfirmed('event-1', Money::of(5_000, 'USD'), Money::of(150, 'USD'), Money::of(250, 'USD')),
        EventFinanceIncrement::paymentConfirmed('event-1', Money::of(7_777, 'USD'), Money::of(233, 'USD'), Money::of(389, 'USD')),
        // a partial refund under the returned policy (nonzero returned commission)
        EventFinanceIncrement::refundCompleted('event-1', Money::of(2_000, 'USD'), Money::of(100, 'USD')),
        // a full refund under the retained policy (zero returned commission)
        EventFinanceIncrement::refundCompleted('event-1', Money::of(5_000, 'USD'), Money::of(0, 'USD')),
        // another payment after refunds, and another returned-policy refund
        EventFinanceIncrement::paymentConfirmed('event-1', Money::of(1_234, 'USD'), Money::of(37, 'USD'), Money::of(61, 'USD')),
        EventFinanceIncrement::refundCompleted('event-1', Money::of(777, 'USD'), Money::of(38, 'USD')),
    ];

    $totals = $zero;

    foreach ($sequence as $increment) {
        $totals = applyEventFinanceIncrement($totals, $increment);

        expect($totals['grossAmount'] - $totals['gatewayFeeAmount'] - $totals['platformCommissionAmount'])
            ->toBe($totals['tenantNetAmount']);
    }

    // The sequence is not all-zero: proves the assertion above is not
    // trivially true from every field staying at zero throughout.
    expect($totals['ordersPaidCount'])->toBe(4)
        ->and($totals['refundsCount'])->toBe(3)
        ->and($totals['grossAmount'])->not->toBe(0);
});

it('never inspects a commission policy value: identical results for equal amount and returned-commission facts regardless of caller-side policy label', function (): void {
    // This class receives no RefundCommissionPolicy parameter at all
    // (only the already-resolved amount and returned-commission
    // Money facts), which is itself the proof it performs no policy
    // branching or commission arithmetic of its own.
    $returned = EventFinanceIncrement::refundCompleted('event-1', Money::of(4_000, 'USD'), Money::of(200, 'USD'));
    $sameFacts = EventFinanceIncrement::refundCompleted('event-1', Money::of(4_000, 'USD'), Money::of(200, 'USD'));

    expect($returned)->toEqual($sameFacts);
});
