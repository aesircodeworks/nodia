<?php

use App\Reporting\Support\DailySalesIncrement;
use App\Support\Money\Money;

/*
 * Stage-11 plan, Slice 1 Unit tests: payload-to-increment mapping. Both
 * TicketIssued and TicketRefunded resolve to a commutative increment of
 * exactly one counter column and one money column each (Domain events
 * "Consumed" table), money staying in minor units end to end with no
 * float ever entering the mapping.
 */

it('maps an issued ticket to a +1 issued count and +price gross increment', function (): void {
    $increment = DailySalesIncrement::issued('event-1', 'type-1', '2026-07-13', Money::of(2_599, 'USD'));

    expect($increment->eventId)->toBe('event-1')
        ->and($increment->ticketTypeId)->toBe('type-1')
        ->and($increment->salesDate)->toBe('2026-07-13')
        ->and($increment->ticketsIssuedCount)->toBe(1)
        ->and($increment->ticketsRefundedCount)->toBe(0)
        ->and($increment->grossAmount)->toBe(2_599)
        ->and($increment->refundedAmount)->toBe(0)
        ->and($increment->currency)->toBe('USD');
});

it('maps a refunded ticket to a +1 refunded count and +price refunded increment, touching no other column', function (): void {
    $increment = DailySalesIncrement::refunded('event-1', 'type-1', '2026-07-13', Money::of(2_599, 'USD'));

    expect($increment->eventId)->toBe('event-1')
        ->and($increment->ticketTypeId)->toBe('type-1')
        ->and($increment->salesDate)->toBe('2026-07-13')
        ->and($increment->ticketsIssuedCount)->toBe(0)
        ->and($increment->ticketsRefundedCount)->toBe(1)
        ->and($increment->grossAmount)->toBe(0)
        ->and($increment->refundedAmount)->toBe(2_599)
        ->and($increment->currency)->toBe('USD');
});

it('carries the resolved list price amount and currency verbatim, never as a float', function (): void {
    $increment = DailySalesIncrement::issued('event-1', 'type-1', '2026-07-13', Money::of(0, 'EUR'));

    expect($increment->grossAmount)->toBeInt()->toBe(0)
        ->and($increment->currency)->toBe('EUR');
});
