<?php

use App\Orders\Enums\QrVerificationOutcome;
use App\Orders\Support\TicketCheckInStatusMapper;

/*
 * Stage-09 plan, Task 8: the pure ticket-status branch of
 * VerifyCheckInQr's mapping, tested without a database round trip.
 */

it('maps issued to valid', function (): void {
    expect(TicketCheckInStatusMapper::classify('issued'))->toBe(QrVerificationOutcome::Valid);
});

it('maps canceled to ticket_canceled', function (): void {
    expect(TicketCheckInStatusMapper::classify('canceled'))->toBe(QrVerificationOutcome::TicketCanceled);
});

it('maps refunded to ticket_refunded', function (): void {
    expect(TicketCheckInStatusMapper::classify('refunded'))->toBe(QrVerificationOutcome::TicketRefunded);
});

it('maps an out-of-enum status to ticket_status_unknown instead of throwing', function (): void {
    expect(TicketCheckInStatusMapper::classify('bogus'))->toBe(QrVerificationOutcome::TicketStatusUnknown);
});
