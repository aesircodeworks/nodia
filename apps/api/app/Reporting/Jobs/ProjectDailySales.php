<?php

namespace App\Reporting\Jobs;

use App\Orders\Actions\GetTicketSaleFacts;
use App\Orders\Data\TicketSaleFactsData;
use App\Reporting\Support\ApplyDailySalesIncrement;
use App\Reporting\Support\DailySalesIncrement;
use App\Reporting\Support\SalesDateBucket;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;

/**
 * The sales projection consumer (stage-11 plan, Domain events
 * "Consumed" table; task 5): a plain, unordered OutboxSubscriber,
 * because every mutation it makes is a commutative increment, so no
 * cross-event ordering is needed for TicketIssued and TicketRefunded to
 * converge to the same state regardless of delivery order (stage-11
 * plan, TDD sequencing preamble). Neither payload carries the ticket
 * type's list price at issue, so both branches resolve event_id,
 * ticket_type_id, and list price through the Orders bulk lookup Action
 * rather than trusting the payload's own copies of those fields
 * (event-conventions: consumers needing more load it through the owning
 * context's Actions).
 */
final readonly class ProjectDailySales implements OutboxSubscriber
{
    public const string NAME = 'project_daily_sales';

    public function __construct(
        private GetTicketSaleFacts $ticketSaleFacts,
        private ApplyDailySalesIncrement $applyIncrement,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        match ($event->type) {
            'TicketIssued' => $this->project($event, issued: true),
            'TicketRefunded' => $this->project($event, issued: false),
            default => null,
        };
    }

    private function project(OutboxEvent $event, bool $issued): void
    {
        $ticketId = (string) $event->payload['ticket_id'];
        $facts = $this->resolveFacts($ticketId);

        // A ticket the sale-facts lookup cannot price (no matching
        // order_item) is a data-integrity gap this read model cannot
        // recover from on its own; skip rather than throw, mirroring
        // App\Orders\Jobs\GenerateTicketPdf's defensive null check. This
        // consumer runs synchronously after commit under the sync queue
        // (production posture: Horizon), so throwing here would surface
        // as a failure on the request that already committed the ticket
        // or refund, which a projection gap must never do; the row is
        // simply absent until reporting:rebuild (task 9) can retry it.
        if ($facts === null) {
            return;
        }

        $salesDate = SalesDateBucket::forInstant($event->occurred_at);

        $increment = $issued
            ? DailySalesIncrement::issued($facts->eventId, $facts->ticketTypeId, $salesDate, $facts->listPrice)
            : DailySalesIncrement::refunded($facts->eventId, $facts->ticketTypeId, $salesDate, $facts->listPrice);

        ($this->applyIncrement)($event->tenant_id, $increment);
    }

    private function resolveFacts(string $ticketId): ?TicketSaleFactsData
    {
        return ($this->ticketSaleFacts)([$ticketId])->get($ticketId);
    }
}
