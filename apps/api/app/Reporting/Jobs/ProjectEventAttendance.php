<?php

namespace App\Reporting\Jobs;

use App\Orders\Actions\GetTicketTypeIds;
use App\Reporting\Support\ApplyEventAttendanceIncrement;
use App\Reporting\Support\EventAttendanceIncrement;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use Carbon\CarbonImmutable;

/**
 * The attendance projection consumer (stage-11 plan, Domain events
 * "Consumed" table; task 11): a plain, unordered OutboxSubscriber, like
 * ProjectDailySales and ProjectEventFinance, because every mutation it
 * makes is either a commutative increment or a LEAST/GREATEST bound, so
 * no cross-event ordering is needed for TicketCheckedIn and
 * DuplicateScanDetected to converge to the same state regardless of
 * delivery order (stage-11 plan, TDD sequencing preamble). Both
 * payloads already carry event_id; neither carries ticket_type_id, so
 * both branches resolve it through the Orders bulk lookup Action rather
 * than reading the tickets table directly (event-conventions: consumers
 * needing more load it through the owning context's Actions).
 */
final readonly class ProjectEventAttendance implements OutboxSubscriber
{
    public const string NAME = 'project_event_attendance';

    public function __construct(
        private GetTicketTypeIds $ticketTypeIds,
        private ApplyEventAttendanceIncrement $applyIncrement,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        match ($event->type) {
            'TicketCheckedIn' => $this->projectTicketCheckedIn($event),
            'DuplicateScanDetected' => $this->projectDuplicateScanDetected($event),
            default => null,
        };
    }

    private function projectTicketCheckedIn(OutboxEvent $event): void
    {
        $ticketTypeId = $this->resolveTicketTypeId((string) $event->payload['ticket_id']);

        // A ticket the bulk lookup cannot resolve is a data-integrity
        // gap this read model cannot recover from on its own; skip
        // rather than throw, mirroring ProjectDailySales' and
        // ProjectEventFinance's own defensive handling of the same
        // class of gap. The row is simply absent until
        // reporting:rebuild (task 13) can retry it.
        if ($ticketTypeId === null) {
            return;
        }

        $scannedAt = CarbonImmutable::parse((string) $event->payload['scanned_at'])->utc();

        $increment = EventAttendanceIncrement::checkedIn((string) $event->payload['event_id'], $ticketTypeId, $scannedAt);

        ($this->applyIncrement)($event->tenant_id, $increment);
    }

    private function projectDuplicateScanDetected(OutboxEvent $event): void
    {
        $ticketTypeId = $this->resolveTicketTypeId((string) $event->payload['ticket_id']);

        if ($ticketTypeId === null) {
            return;
        }

        $increment = EventAttendanceIncrement::duplicate((string) $event->payload['event_id'], $ticketTypeId);

        ($this->applyIncrement)($event->tenant_id, $increment);
    }

    private function resolveTicketTypeId(string $ticketId): ?string
    {
        return ($this->ticketTypeIds)([$ticketId])->get($ticketId);
    }
}
