<?php

namespace App\Reporting\Jobs;

use App\Orders\Actions\GetTicketSaleFacts;
use App\Orders\Data\TicketSaleFactsData;
use App\Reporting\Models\DailySales;
use App\Reporting\Support\ApplyDailySalesIncrement;
use App\Reporting\Support\DailySalesIncrement;
use App\Reporting\Support\Rebuild\RebuildableProjection;
use App\Reporting\Support\SalesDateBucket;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use App\Support\Outbox\ProjectionLockedSubscriber;
use Illuminate\Database\Eloquent\Model;

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
 *
 * Also ProjectionLockedSubscriber and RebuildableProjection (stage-11
 * plan, task 13): computeIncrement() is the same increment-resolution
 * logic handle() applies, exposed separately so
 * App\Reporting\Support\Rebuild\ReportingProjectionRebuilder can drive
 * it directly (replay-and-write) or fold it in memory (--verify)
 * without a second, divergent implementation of "what does this event
 * mean to this projection".
 */
final readonly class ProjectDailySales implements OutboxSubscriber, ProjectionLockedSubscriber, RebuildableProjection
{
    public const string NAME = 'project_daily_sales';

    public function __construct(
        private GetTicketSaleFacts $ticketSaleFacts,
        private ApplyDailySalesIncrement $applyIncrement,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $increment = $this->computeIncrement($event);

        if ($increment !== null) {
            ($this->applyIncrement)($event->tenant_id, $increment);
        }
    }

    public function projectionLockKey(): string
    {
        return self::NAME;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function modelClass(): string
    {
        return DailySales::class;
    }

    public function keyColumns(): array
    {
        return ['event_id', 'ticket_type_id', 'sales_date'];
    }

    public function computeIncrement(OutboxEvent $event): ?DailySalesIncrement
    {
        return match ($event->type) {
            'TicketIssued' => $this->resolveIncrement($event, issued: true),
            'TicketRefunded' => $this->resolveIncrement($event, issued: false),
            default => null,
        };
    }

    private function resolveIncrement(OutboxEvent $event, bool $issued): ?DailySalesIncrement
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
        // simply absent until reporting:rebuild (task 13) can retry it.
        if ($facts === null) {
            return null;
        }

        $salesDate = SalesDateBucket::forInstant($event->occurred_at);

        return $issued
            ? DailySalesIncrement::issued($facts->eventId, $facts->ticketTypeId, $salesDate, $facts->listPrice)
            : DailySalesIncrement::refunded($facts->eventId, $facts->ticketTypeId, $salesDate, $facts->listPrice);
    }

    private function resolveFacts(string $ticketId): ?TicketSaleFactsData
    {
        return ($this->ticketSaleFacts)([$ticketId])->get($ticketId);
    }

    public function keyFor(object $increment): array
    {
        return [
            'event_id' => $increment->eventId,
            'ticket_type_id' => $increment->ticketTypeId,
            'sales_date' => $increment->salesDate,
        ];
    }

    public function fold(?array $row, object $increment): array
    {
        $row ??= [
            'tickets_issued_count' => 0,
            'tickets_refunded_count' => 0,
            'gross_amount' => 0,
            'refunded_amount' => 0,
            'currency' => null,
        ];

        $row['tickets_issued_count'] += $increment->ticketsIssuedCount;
        $row['tickets_refunded_count'] += $increment->ticketsRefundedCount;
        $row['gross_amount'] += $increment->grossAmount;
        $row['refunded_amount'] += $increment->refundedAmount;
        $row['currency'] ??= $increment->currency;

        return $row;
    }

    public function rowFromModel(Model $model): array
    {
        return [
            'tickets_issued_count' => (int) $model->getAttribute('tickets_issued_count'),
            'tickets_refunded_count' => (int) $model->getAttribute('tickets_refunded_count'),
            'gross_amount' => (int) $model->getAttribute('gross_amount'),
            'refunded_amount' => (int) $model->getAttribute('refunded_amount'),
            'currency' => (string) $model->getAttribute('currency'),
        ];
    }
}
