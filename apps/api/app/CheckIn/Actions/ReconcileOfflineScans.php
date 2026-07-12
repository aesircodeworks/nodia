<?php

namespace App\CheckIn\Actions;

use App\CheckIn\Data\BatchResultData;
use App\CheckIn\Data\CheckEventAssignmentData;
use App\CheckIn\Data\OfflineScanData;
use App\CheckIn\Data\ReconcileBatchData;
use App\CheckIn\Data\ScanOutcomeData;
use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Enums\ScanOutcome;
use App\CheckIn\Events\DuplicateScanDetected;
use App\CheckIn\Events\DuplicateScanDetectedPayload;
use App\CheckIn\Events\TicketCheckedIn;
use App\CheckIn\Events\TicketCheckedInPayload;
use App\CheckIn\Exceptions\BatchTooLargeException;
use App\CheckIn\Models\CheckIn;
use App\Identity\Actions\ResolveActingCapabilities;
use App\Orders\Actions\VerifyCheckInQr;
use App\Orders\Data\VerifyCheckInQrData;
use App\Orders\Enums\QrVerificationOutcome;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Problems\ErrorCode;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POST /v1/check-in-batches (stage-09 plan, Endpoints "POST
 * /v1/check-in-batches", Slice 5). Processes up to 500 offline scans per
 * request, never failing the batch wholesale for a per-scan problem: a
 * malformed QR, an unassigned event, or a stale rotation counter yields
 * a rejected outcome for that scan alone. Idempotent per persisted scan
 * by (device_id, client_scan_id); a scan that only ever produced a
 * rejected outcome is not persisted, so resubmitting it re-verifies from
 * scratch rather than replaying a stored result (plan, "POST
 * /v1/check-in-batches" prose).
 *
 * Cross-device resolution is first-scan-wins by scanned_at, tie-broken
 * by the smallest client_scan_id, evaluated per ticket independently of
 * submission order: an accepted insert that loses the race to an
 * earlier-timestamped scan already on file persists as duplicate; an
 * accepted insert that beats a later-timestamped scan already on file
 * swaps in via a conditional UPDATE demoting the existing row (affected-
 * row count checked), which is the only path that records
 * DuplicateScanDetected for a row other than the one just inserted.
 * TicketCheckedIn is recorded exactly once per ticket, on the very first
 * accepted insert only; a later swap never re-records it (plan, Domain
 * events "Produced", TicketCheckedIn).
 */
final class ReconcileOfflineScans
{
    private const FUTURE_TOLERANCE_MINUTES = 5;

    private const MAX_SCANS = 500;

    private const MAX_RESOLUTION_ATTEMPTS = 10;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly VerifyCheckInQr $verify,
        private readonly ResolveActingCapabilities $resolveCapabilities,
        private readonly CheckEventAssignment $checkEventAssignment,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function __invoke(ReconcileBatchData $data, string $userId): BatchResultData
    {
        if (count($data->scans) > self::MAX_SCANS) {
            throw BatchTooLargeException::make();
        }

        $tenantId = $this->tenantContext->tenantId();
        $capabilities = ($this->resolveCapabilities)($userId);

        $results = [];

        foreach ($data->scans as $scan) {
            $results[] = $this->resolveOne($tenantId, $data->deviceId, $userId, $capabilities, $scan);
        }

        return new BatchResultData($results);
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function resolveOne(string $tenantId, string $deviceId, string $userId, array $capabilities, OfflineScanData $scan): ScanOutcomeData
    {
        $replay = CheckIn::query()
            ->where('tenant_id', $tenantId)
            ->where('device_id', $deviceId)
            ->where('client_scan_id', $scan->clientScanId)
            ->first();

        if ($replay !== null) {
            return $this->outcomeFromRow($scan->clientScanId, $replay);
        }

        $scannedAt = CarbonImmutable::parse($scan->scannedAt);

        if ($scannedAt->greaterThan(CarbonImmutable::instance(Date::now())->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
            return $this->rejected($scan->clientScanId, ErrorCode::ScannedAtInFuture);
        }

        $verification = ($this->verify)(new VerifyCheckInQrData($scan->qrPayload));
        $rejectedCode = $this->rejectionCode($verification->outcome);

        if ($rejectedCode !== null) {
            return $this->rejected($scan->clientScanId, $rejectedCode);
        }

        /** @var string $ticketId */
        $ticketId = $verification->ticketId;
        /** @var string $eventId */
        $eventId = $verification->eventId;

        $assignment = ($this->checkEventAssignment)(new CheckEventAssignmentData(
            userId: $userId,
            eventId: $eventId,
            capabilities: $capabilities,
        ));

        if (! $assignment->authorized) {
            return $this->rejected($scan->clientScanId, ErrorCode::CheckinNotAssigned);
        }

        return $this->resolveAgainstExisting($tenantId, $ticketId, $eventId, $userId, $deviceId, $scan->clientScanId, $scannedAt);
    }

    private function resolveAgainstExisting(
        string $tenantId,
        string $ticketId,
        string $eventId,
        string $userId,
        string $deviceId,
        string $clientScanId,
        CarbonImmutable $scannedAt,
    ): ScanOutcomeData {
        for ($attempt = 0; $attempt < self::MAX_RESOLUTION_ATTEMPTS; $attempt++) {
            $row = $this->attemptResolution(
                $tenantId,
                $ticketId,
                $eventId,
                $userId,
                $deviceId,
                $clientScanId,
                $scannedAt,
            );

            if ($row !== null) {
                return $this->outcomeFromRow($clientScanId, $row);
            }
        }

        throw new \RuntimeException(sprintf(
            'check_ins: exhausted %d resolution attempts for ticket "%s".',
            self::MAX_RESOLUTION_ATTEMPTS,
            $ticketId,
        ));
    }

    private function attemptResolution(
        string $tenantId,
        string $ticketId,
        string $eventId,
        string $userId,
        string $deviceId,
        string $clientScanId,
        CarbonImmutable $scannedAt,
    ): ?CheckIn {
        $checkInId = (string) Str::uuid7();
        $syncedAt = Date::now();

        try {
            // Nested transaction (Postgres savepoint): a caught unique
            // violation still poisons the *current* Postgres transaction
            // until rolled back, so this insert attempt must be isolated
            // from the outer DB::transaction() the caller opened,
            // mirroring App\CheckIn\Actions\RecordScan's own nested-
            // transaction precedent for the identical reason.
            return DB::transaction(function () use (
                $tenantId,
                $ticketId,
                $eventId,
                $userId,
                $deviceId,
                $clientScanId,
                $scannedAt,
                $checkInId,
                $syncedAt,
            ): CheckIn {
                $accepted = CheckIn::query()->create([
                    'id' => $checkInId,
                    'tenant_id' => $tenantId,
                    'ticket_id' => $ticketId,
                    'event_id' => $eventId,
                    'user_id' => $userId,
                    'device_id' => $deviceId,
                    'client_scan_id' => $clientScanId,
                    'result' => CheckInResult::Accepted,
                    'scanned_at' => $scannedAt,
                    'synced_at' => $syncedAt,
                ]);

                $this->outbox->record(new TicketCheckedIn(
                    $tenantId,
                    $ticketId,
                    new TicketCheckedInPayload(
                        checkInId: $accepted->id,
                        ticketId: $ticketId,
                        eventId: $eventId,
                        deviceId: $deviceId,
                        userId: $userId,
                        scannedAt: $this->format($scannedAt),
                    ),
                ));

                return $accepted;
            });
        } catch (UniqueConstraintViolationException) {
            // Fall through: an accepted row already exists for this
            // ticket. Re-fetched below under the same transaction so the
            // swap decision is made against current data.
        }

        // Everything from here on (the existing-row lock, the demote, and
        // the follow-up insert) runs as one Postgres transaction so the
        // swap is atomic end to end; a nested savepoint isolates the
        // follow-up insert's own possible unique-violation retry from
        // this outer transaction, the same reason the first insert
        // attempt above needs its own nested transaction.
        try {
            return DB::transaction(function () use (
                $tenantId,
                $ticketId,
                $eventId,
                $userId,
                $deviceId,
                $clientScanId,
                $scannedAt,
                $checkInId,
                $syncedAt,
            ): ?CheckIn {
                $existing = CheckIn::query()
                    ->where('tenant_id', $tenantId)
                    ->where('ticket_id', $ticketId)
                    ->where('result', CheckInResult::Accepted)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    // Raced with a concurrent demotion between the failed
                    // insert and this fetch; retry the whole resolution.
                    return null;
                }

                $incomingWins = $this->beats($scannedAt, $clientScanId, $existing);

                if (! $incomingWins) {
                    $duplicate = CheckIn::query()->create([
                        'id' => $checkInId,
                        'tenant_id' => $tenantId,
                        'ticket_id' => $ticketId,
                        'event_id' => $eventId,
                        'user_id' => $userId,
                        'device_id' => $deviceId,
                        'client_scan_id' => $clientScanId,
                        'result' => CheckInResult::Duplicate,
                        'scanned_at' => $scannedAt,
                        'synced_at' => $syncedAt,
                    ]);

                    $this->outbox->record(new DuplicateScanDetected(
                        $tenantId,
                        $ticketId,
                        new DuplicateScanDetectedPayload(
                            checkInId: $duplicate->id,
                            ticketId: $ticketId,
                            eventId: $eventId,
                            deviceId: $deviceId,
                            userId: $userId,
                            scannedAt: $this->format($scannedAt),
                            firstCheckInId: $existing->id,
                            firstScannedAt: $this->format($existing->scanned_at),
                            firstDeviceId: $existing->device_id,
                        ),
                    ));

                    return $duplicate;
                }

                $demoted = DB::table('check_ins')
                    ->where('id', $existing->id)
                    ->where('tenant_id', $tenantId)
                    ->where('ticket_id', $ticketId)
                    ->where('result', CheckInResult::Accepted->value)
                    ->whereRaw('(scanned_at, client_scan_id) > (?, ?)', [$scannedAt->toDateTimeString(), $clientScanId])
                    ->update(['result' => CheckInResult::Duplicate->value, 'updated_at' => Date::now()]);

                if ($demoted !== 1) {
                    // Someone else already resolved this ticket
                    // differently between the lockForUpdate() read and
                    // this UPDATE; retry.
                    return null;
                }

                try {
                    $accepted = DB::transaction(fn (): CheckIn => CheckIn::query()->create([
                        'id' => $checkInId,
                        'tenant_id' => $tenantId,
                        'ticket_id' => $ticketId,
                        'event_id' => $eventId,
                        'user_id' => $userId,
                        'device_id' => $deviceId,
                        'client_scan_id' => $clientScanId,
                        'result' => CheckInResult::Accepted,
                        'scanned_at' => $scannedAt,
                        'synced_at' => $syncedAt,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // The partial unique index rejected this insert even
                    // though the demote just above freed the slot:
                    // extremely unlikely (would need a third concurrent
                    // accepted insert to win the race in between), but
                    // retried rather than assumed away.
                    return null;
                }

                $this->outbox->record(new DuplicateScanDetected(
                    $tenantId,
                    $ticketId,
                    new DuplicateScanDetectedPayload(
                        checkInId: $existing->id,
                        ticketId: $ticketId,
                        eventId: $eventId,
                        deviceId: $existing->device_id,
                        userId: $existing->user_id,
                        scannedAt: $this->format($existing->scanned_at),
                        firstCheckInId: $accepted->id,
                        firstScannedAt: $this->format($scannedAt),
                        firstDeviceId: $deviceId,
                    ),
                ));

                return $accepted;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker's insert or demote landed between this
            // attempt's own operations in a way that still violated a
            // unique index despite the checks above; retry the whole
            // resolution rather than propagate.
            return null;
        }
    }

    /**
     * True when the incoming scan should win over $existing: earlier
     * scanned_at, or an equal scanned_at with a smaller client_scan_id
     * (plan, "POST /v1/check-in-batches" reconciliation semantics).
     */
    private function beats(CarbonImmutable $scannedAt, string $clientScanId, CheckIn $existing): bool
    {
        $existingScannedAt = CarbonImmutable::instance($existing->scanned_at);

        if (! $scannedAt->equalTo($existingScannedAt)) {
            return $scannedAt->lessThan($existingScannedAt);
        }

        return $clientScanId < $existing->client_scan_id;
    }

    private function outcomeFromRow(string $clientScanId, CheckIn $row): ScanOutcomeData
    {
        if ($row->result === CheckInResult::Accepted) {
            return new ScanOutcomeData(
                clientScanId: $clientScanId,
                outcome: ScanOutcome::Accepted,
                checkInId: $row->id,
            );
        }

        $first = CheckIn::query()
            ->where('tenant_id', $row->tenant_id)
            ->where('ticket_id', $row->ticket_id)
            ->where('result', CheckInResult::Accepted)
            ->first();

        return new ScanOutcomeData(
            clientScanId: $clientScanId,
            outcome: ScanOutcome::Duplicate,
            checkInId: $row->id,
            code: ErrorCode::TicketAlreadyCheckedIn->value,
            firstScannedAt: $first !== null ? $this->format($first->scanned_at) : null,
            firstDeviceId: $first?->device_id,
        );
    }

    private function rejected(string $clientScanId, ErrorCode $code): ScanOutcomeData
    {
        return new ScanOutcomeData(
            clientScanId: $clientScanId,
            outcome: ScanOutcome::Rejected,
            code: $code->value,
        );
    }

    private function rejectionCode(QrVerificationOutcome $outcome): ?ErrorCode
    {
        return match ($outcome) {
            QrVerificationOutcome::Valid => null,
            QrVerificationOutcome::SignatureInvalid => ErrorCode::QrSignatureInvalid,
            QrVerificationOutcome::KeyRevoked => ErrorCode::QrKeyRevoked,
            QrVerificationOutcome::RotationStale => ErrorCode::TicketRotationStale,
            QrVerificationOutcome::TicketNotFound => ErrorCode::CheckInTicketNotFound,
            QrVerificationOutcome::TicketCanceled => ErrorCode::CheckInTicketCanceled,
            QrVerificationOutcome::TicketRefunded => ErrorCode::CheckInTicketRefunded,
            QrVerificationOutcome::TicketStatusUnknown => ErrorCode::ServerInternalError,
        };
    }

    private function format(mixed $timestamp): string
    {
        return CarbonImmutable::instance($timestamp)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
