<?php

namespace App\Reporting\Jobs;

use App\Orders\Actions\GetTicketTypeIds;
use App\Reporting\Models\EventAttendance;
use App\Reporting\Support\ApplyEventAttendanceIncrement;
use App\Reporting\Support\EventAttendanceIncrement;
use App\Reporting\Support\Rebuild\RebuildableProjection;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use App\Support\Outbox\ProjectionLockedSubscriber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

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
 *
 * Also ProjectionLockedSubscriber and RebuildableProjection (stage-11
 * plan, task 13): computeIncrement() is the same increment-resolution
 * logic handle() applies, exposed separately so
 * App\Reporting\Support\Rebuild\ReportingProjectionRebuilder can drive
 * it directly (replay-and-write) or fold it in memory (--verify).
 * fold()/rowFromModel() format the two scan-timestamp bounds as
 * fixed-width UTC ISO 8601 strings so PHP min()/max() reproduce
 * Postgres's LEAST/GREATEST ordering exactly, including its NULL-
 * ignoring semantics (a duplicate()'s null bounds never move an
 * already-set one), without needing a live database to compute them.
 */
final readonly class ProjectEventAttendance implements OutboxSubscriber, ProjectionLockedSubscriber, RebuildableProjection
{
    public const string NAME = 'project_event_attendance';

    public function __construct(
        private GetTicketTypeIds $ticketTypeIds,
        private ApplyEventAttendanceIncrement $applyIncrement,
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
        return EventAttendance::class;
    }

    public function keyColumns(): array
    {
        return ['event_id', 'ticket_type_id'];
    }

    public function computeIncrement(OutboxEvent $event): ?EventAttendanceIncrement
    {
        return match ($event->type) {
            'TicketCheckedIn' => $this->resolveTicketCheckedInIncrement($event),
            'DuplicateScanDetected' => $this->resolveDuplicateScanDetectedIncrement($event),
            default => null,
        };
    }

    private function resolveTicketCheckedInIncrement(OutboxEvent $event): ?EventAttendanceIncrement
    {
        $ticketTypeId = $this->resolveTicketTypeId((string) $event->payload['ticket_id']);

        // A ticket the bulk lookup cannot resolve is a data-integrity
        // gap this read model cannot recover from on its own; skip
        // rather than throw, mirroring ProjectDailySales' and
        // ProjectEventFinance's own defensive handling of the same
        // class of gap. The row is simply absent until
        // reporting:rebuild (task 13) can retry it.
        if ($ticketTypeId === null) {
            return null;
        }

        $scannedAt = CarbonImmutable::parse((string) $event->payload['scanned_at'])->utc();

        return EventAttendanceIncrement::checkedIn((string) $event->payload['event_id'], $ticketTypeId, $scannedAt);
    }

    private function resolveDuplicateScanDetectedIncrement(OutboxEvent $event): ?EventAttendanceIncrement
    {
        $ticketTypeId = $this->resolveTicketTypeId((string) $event->payload['ticket_id']);

        if ($ticketTypeId === null) {
            return null;
        }

        return EventAttendanceIncrement::duplicate((string) $event->payload['event_id'], $ticketTypeId);
    }

    private function resolveTicketTypeId(string $ticketId): ?string
    {
        return ($this->ticketTypeIds)([$ticketId])->get($ticketId);
    }

    public function keyFor(object $increment): array
    {
        return [
            'event_id' => $increment->eventId,
            'ticket_type_id' => $increment->ticketTypeId,
        ];
    }

    public function fold(?array $row, object $increment): array
    {
        $row ??= [
            'checked_in_count' => 0,
            'duplicate_scan_count' => 0,
            'first_scan_at' => null,
            'last_scan_at' => null,
        ];

        $row['checked_in_count'] += $increment->checkedInCount;
        $row['duplicate_scan_count'] += $increment->duplicateScanCount;
        $row['first_scan_at'] = self::earliest($row['first_scan_at'], self::formatTimestamp($increment->firstScanAt));
        $row['last_scan_at'] = self::latest($row['last_scan_at'], self::formatTimestamp($increment->lastScanAt));

        return $row;
    }

    public function rowFromModel(Model $model): array
    {
        $firstScanAt = $model->getAttribute('first_scan_at');
        $lastScanAt = $model->getAttribute('last_scan_at');

        return [
            'checked_in_count' => (int) $model->getAttribute('checked_in_count'),
            'duplicate_scan_count' => (int) $model->getAttribute('duplicate_scan_count'),
            'first_scan_at' => self::formatTimestamp($firstScanAt !== null ? CarbonImmutable::instance($firstScanAt) : null),
            'last_scan_at' => self::formatTimestamp($lastScanAt !== null ? CarbonImmutable::instance($lastScanAt) : null),
        ];
    }

    private static function formatTimestamp(?CarbonImmutable $timestamp): ?string
    {
        return $timestamp?->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Postgres's LEAST()/GREATEST() ignore NULL arguments (returning
     * NULL only when every argument is NULL), the semantics
     * ApplyEventAttendanceIncrement relies on for the live SQL upsert;
     * these mirror that exactly for the in-memory fold.
     */
    private static function earliest(?string $a, ?string $b): ?string
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => $a <= $b ? $a : $b,
        };
    }

    private static function latest(?string $a, ?string $b): ?string
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => $a >= $b ? $a : $b,
        };
    }
}
