<?php

namespace App\CheckIn\Data;

/**
 * Internal return value of App\CheckIn\Actions\RecordScan, never
 * serialized to the wire. wasReplay tells the controller whether to
 * answer 200 (a replayed (device_id, client_scan_id) pair, nothing new
 * recorded) or 201 (a fresh scan). alreadyCheckedIn is set instead when
 * the accepted-ticket partial unique index rejected the insert: the
 * duplicate row and its DuplicateScanDetected event have already
 * committed by the time this is returned (stage-09 plan, Endpoints
 * "POST /v1/check-ins"), so RecordScan returns rather than throws here
 * -- App\Tenancy\Http\Middleware\TransactsRequests rolls the whole
 * request transaction back behind any rendered exception, which would
 * silently undo the very side effect this response documents as
 * persisted, mirroring PaymentController's own 402-as-response
 * precedent for the same reason.
 */
final readonly class RecordScanOutcome
{
    public function __construct(
        public CheckInResultData $data,
        public bool $wasReplay,
        public ?AlreadyCheckedInData $alreadyCheckedIn = null,
    ) {}
}
