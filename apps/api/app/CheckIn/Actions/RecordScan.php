<?php

namespace App\CheckIn\Actions;

use App\CheckIn\Data\AlreadyCheckedInData;
use App\CheckIn\Data\CheckEventAssignmentData;
use App\CheckIn\Data\CheckInResultData;
use App\CheckIn\Data\RecordScanData;
use App\CheckIn\Data\RecordScanOutcome;
use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Events\DuplicateScanDetected;
use App\CheckIn\Events\DuplicateScanDetectedPayload;
use App\CheckIn\Events\TicketCheckedIn;
use App\CheckIn\Events\TicketCheckedInPayload;
use App\CheckIn\Exceptions\CheckinNotAssignedException;
use App\CheckIn\Exceptions\CheckInTicketCanceledException;
use App\CheckIn\Exceptions\CheckInTicketNotFoundException;
use App\CheckIn\Exceptions\CheckInTicketRefundedException;
use App\CheckIn\Exceptions\QrKeyRevokedException;
use App\CheckIn\Exceptions\QrSignatureInvalidException;
use App\CheckIn\Exceptions\ScannedAtInFutureException;
use App\CheckIn\Exceptions\TicketRotationStaleException;
use App\CheckIn\Models\CheckIn;
use App\Identity\Actions\ResolveActingCapabilities;
use App\Orders\Actions\VerifyCheckInQr;
use App\Orders\Data\VerifyCheckInQrData;
use App\Orders\Enums\QrVerificationOutcome;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * POST /v1/check-ins (stage-09 plan, Endpoints "POST /v1/check-ins",
 * Slice 4). Verifies the scanned QR through Orders' VerifyCheckInQr
 * before authorizing, since authorization is against the event named
 * inside the *verified* payload (plan, "Authorization semantics"): a
 * malformed or forged payload never leaks which event's assignment list
 * it would have needed. First-scan-wins is enforced structurally by the
 * check_ins_accepted_ticket_idx partial unique index, never a
 * read-then-write check: the accepted insert is attempted first, and a
 * losing insert falls back to a duplicate row in the same outer
 * transaction, so the duplicate row and its DuplicateScanDetected event
 * always commit even though the call still raises 409 to the caller
 * (system-design 11, "flag, never drop"; stage-09 plan, Open questions
 * "ticket_already_checked_in as 409-with-side-effect"). A replay of the
 * same (device_id, client_scan_id) short-circuits only after the QR is
 * verified and the caller is authorized for the verified event, returning
 * the original persisted result and recording nothing new.
 */
final class RecordScan
{
    // No documented tolerance value; 5 minutes covers ordinary NTP-level
    // client clock drift without opening a wide fraud window (stage-09
    // plan, Open questions "Client clock trust" — deliberately narrow,
    // revisit if fraud follow-up needs a different figure).
    private const FUTURE_TOLERANCE_MINUTES = 5;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly VerifyCheckInQr $verify,
        private readonly ResolveActingCapabilities $resolveCapabilities,
        private readonly CheckEventAssignment $checkEventAssignment,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function __invoke(RecordScanData $data, string $userId): RecordScanOutcome
    {
        $tenantId = $this->tenantContext->tenantId();

        $scannedAt = CarbonImmutable::parse($data->scannedAt);

        if ($scannedAt->greaterThan(CarbonImmutable::instance(Date::now())->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
            throw ScannedAtInFutureException::make();
        }

        $verification = ($this->verify)(new VerifyCheckInQrData($data->qrPayload));

        $this->assertVerified($verification->outcome);

        /** @var string $ticketId */
        $ticketId = $verification->ticketId;
        /** @var string $eventId */
        $eventId = $verification->eventId;

        $this->assertAuthorized($userId, $eventId);

        // Replay short-circuit only after the QR is verified and the caller
        // is authorized for the verified event, so a replay never leaks a
        // stored result to an unauthorized or unassigned caller.
        $replay = CheckIn::query()
            ->where('tenant_id', $tenantId)
            ->where('device_id', $data->deviceId)
            ->where('client_scan_id', $data->clientScanId)
            ->first();

        if ($replay !== null) {
            return new RecordScanOutcome(CheckInResultData::fromModel($replay), wasReplay: true);
        }

        [$result, $checkIn, $problem, $wasReplay] = DB::transaction(function () use ($tenantId, $ticketId, $eventId, $userId, $data, $scannedAt): array {
            $checkInId = (string) Str::uuid7();
            $syncedAt = Date::now();

            try {
                $accepted = DB::transaction(function () use ($tenantId, $ticketId, $eventId, $userId, $data, $scannedAt, $checkInId, $syncedAt): CheckIn {
                    return CheckIn::query()->create([
                        'id' => $checkInId,
                        'tenant_id' => $tenantId,
                        'ticket_id' => $ticketId,
                        'event_id' => $eventId,
                        'user_id' => $userId,
                        'device_id' => $data->deviceId,
                        'client_scan_id' => $data->clientScanId,
                        'result' => CheckInResult::Accepted,
                        'scanned_at' => $scannedAt,
                        'synced_at' => $syncedAt,
                    ]);
                });

                $this->outbox->record(new TicketCheckedIn(
                    $tenantId,
                    $ticketId,
                    new TicketCheckedInPayload(
                        checkInId: $accepted->id,
                        ticketId: $ticketId,
                        eventId: $eventId,
                        deviceId: $data->deviceId,
                        userId: $userId,
                        scannedAt: $this->format($scannedAt),
                    ),
                ));

                return [CheckInResult::Accepted, $accepted, null, false];
            } catch (UniqueConstraintViolationException) {
                // A concurrent request replaying the same (device_id,
                // client_scan_id) committed its row between this call's
                // opening replay probe and this insert: the losing insert
                // trips the (tenant_id, device_id, client_scan_id) unique
                // index, not the accepted-ticket one, so treat it as the
                // replay it is and return the original persisted result
                // rather than misclassifying it as a ticket duplicate.
                $replay = CheckIn::query()
                    ->where('tenant_id', $tenantId)
                    ->where('device_id', $data->deviceId)
                    ->where('client_scan_id', $data->clientScanId)
                    ->first();

                if ($replay !== null) {
                    return [$replay->result, $replay, null, true];
                }

                $existing = CheckIn::query()
                    ->where('ticket_id', $ticketId)
                    ->where('result', CheckInResult::Accepted)
                    ->firstOrFail();

                $duplicate = CheckIn::query()->create([
                    'id' => $checkInId,
                    'tenant_id' => $tenantId,
                    'ticket_id' => $ticketId,
                    'event_id' => $eventId,
                    'user_id' => $userId,
                    'device_id' => $data->deviceId,
                    'client_scan_id' => $data->clientScanId,
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
                        deviceId: $data->deviceId,
                        userId: $userId,
                        scannedAt: $this->format($scannedAt),
                        firstCheckInId: $existing->id,
                        firstScannedAt: $this->format($existing->scanned_at),
                        firstDeviceId: $existing->device_id,
                    ),
                ));

                return [CheckInResult::Duplicate, $duplicate, $existing, false];
            }
        });

        if ($wasReplay) {
            return new RecordScanOutcome(CheckInResultData::fromModel($checkIn), wasReplay: true);
        }

        if ($result === CheckInResult::Duplicate) {
            /** @var CheckIn $problem */
            return new RecordScanOutcome(
                CheckInResultData::fromModel($checkIn),
                wasReplay: false,
                alreadyCheckedIn: new AlreadyCheckedInData(
                    $this->format($problem->scanned_at),
                    $problem->device_id,
                ),
            );
        }

        return new RecordScanOutcome(CheckInResultData::fromModel($checkIn), wasReplay: false);
    }

    private function assertVerified(QrVerificationOutcome $outcome): void
    {
        match ($outcome) {
            QrVerificationOutcome::Valid => null,
            QrVerificationOutcome::SignatureInvalid => throw QrSignatureInvalidException::make(),
            QrVerificationOutcome::KeyRevoked => throw QrKeyRevokedException::make(),
            QrVerificationOutcome::RotationStale => throw TicketRotationStaleException::make(),
            QrVerificationOutcome::TicketNotFound => throw CheckInTicketNotFoundException::make(),
            QrVerificationOutcome::TicketCanceled => throw CheckInTicketCanceledException::make(),
            QrVerificationOutcome::TicketRefunded => throw CheckInTicketRefundedException::make(),
            QrVerificationOutcome::TicketStatusUnknown => throw new RuntimeException(
                'check_ins: ticket status classified as unknown; refusing to record a scan.',
            ),
        };
    }

    private function assertAuthorized(string $userId, string $eventId): void
    {
        $capabilities = ($this->resolveCapabilities)($userId);

        $result = ($this->checkEventAssignment)(new CheckEventAssignmentData(
            userId: $userId,
            eventId: $eventId,
            capabilities: $capabilities,
        ));

        if (! $result->authorized) {
            throw CheckinNotAssignedException::forEvent($eventId);
        }
    }

    private function format(mixed $timestamp): string
    {
        return CarbonImmutable::instance($timestamp)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
