<?php

namespace App\Reporting\Support;

use App\Support\Money\Money;

/**
 * The commutative increment one TicketIssued or TicketRefunded event
 * contributes to one report_daily_sales cell (stage-11 plan, Domain
 * events "Consumed" table): issued touches only the issued count and
 * gross columns, refunded touches only the refunded count and refunded
 * columns, so unordered delivery and replay converge regardless of which
 * arrives first. Both money columns carry pre-discount face value, the
 * ticket type's list price at issue, per the "money semantics" note on
 * App\Reporting\Models\DailySales; this value object never performs
 * money math of its own, it only carries the resolved list price into
 * the increment ApplyDailySalesIncrement writes.
 */
final readonly class DailySalesIncrement
{
    private function __construct(
        public string $eventId,
        public string $ticketTypeId,
        public string $salesDate,
        public int $ticketsIssuedCount,
        public int $ticketsRefundedCount,
        public int $grossAmount,
        public int $refundedAmount,
        public string $currency,
    ) {}

    public static function issued(string $eventId, string $ticketTypeId, string $salesDate, Money $listPrice): self
    {
        return new self($eventId, $ticketTypeId, $salesDate, 1, 0, $listPrice->amount, 0, $listPrice->currency);
    }

    public static function refunded(string $eventId, string $ticketTypeId, string $salesDate, Money $listPrice): self
    {
        return new self($eventId, $ticketTypeId, $salesDate, 0, 1, 0, $listPrice->amount, $listPrice->currency);
    }
}
