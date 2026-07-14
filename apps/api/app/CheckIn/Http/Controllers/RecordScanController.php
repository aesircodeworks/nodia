<?php

namespace App\CheckIn\Http\Controllers;

use App\CheckIn\Actions\RecordScan;
use App\CheckIn\Data\RecordScanData;
use App\CheckIn\Data\RecordScanOutcome;
use App\Support\Audit\ActivityLogger;
use App\Support\Problems\ErrorCode;
use App\Support\Problems\ProblemData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/check-ins (stage-09 plan, Endpoints "POST /v1/check-ins",
 * Slice 4). Authorization (checkin.scan plus assignment for the event
 * named inside the verified QR payload, or checkin.manage as a bypass)
 * happens inside App\CheckIn\Actions\RecordScan itself, since the target
 * event is not known until the payload has been verified. The
 * ticket_already_checked_in 409 is built and returned here directly
 * rather than thrown, mirroring App\Payments\Http\Controllers\
 * PaymentController's own 402-as-response precedent: the duplicate row
 * and its DuplicateScanDetected event have already committed by the
 * time RecordScan returns, and throwing would trip
 * App\Tenancy\Http\Middleware\TransactsRequests's rollback-on-rendered-
 * error rule, silently undoing that persisted side effect.
 *
 * That same 409-with-side-effect shape is why the activity-log entry
 * (system-design 14.2, "all staff actions") is recorded here rather than
 * by App\Http\Middleware\RecordActivityAudit on the route: the middleware
 * only records successful responses, so a duplicate scan, which persists
 * a check_ins row and an outbox event yet answers 409, would never reach
 * it. Recording from the controller keeps the request user as the causer
 * (the Action only carries the staff id as a string) and runs inside the
 * ambient request transaction, so the entry commits with the scan it
 * describes. A replay records nothing new, matching RecordScan, which
 * persists nothing new either.
 */
class RecordScanController
{
    public function __construct(
        private readonly RecordScan $recordScan,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function store(RecordScanData $data, Request $request): JsonResponse
    {
        $userId = (string) $request->user('staff')?->getAuthIdentifier();

        $outcome = ($this->recordScan)($data, $userId);

        $this->audit($outcome, $request);

        if ($outcome->alreadyCheckedIn !== null) {
            $problem = ProblemData::fromErrorCode(
                ErrorCode::TicketAlreadyCheckedIn,
                'This ticket has already been checked in.',
                $request->headers->get('X-Correlation-Id'),
            );

            return response()->json(
                [
                    ...$problem->toArray(),
                    'first_scanned_at' => $outcome->alreadyCheckedIn->firstScannedAt,
                    'first_device_id' => $outcome->alreadyCheckedIn->firstDeviceId,
                ],
                $problem->status,
                ['Content-Type' => 'application/problem+json'],
            );
        }

        return response()->json($outcome->data, $outcome->wasReplay ? 200 : 201);
    }

    private function audit(RecordScanOutcome $outcome, Request $request): void
    {
        if ($outcome->wasReplay) {
            return;
        }

        $causer = $request->user('staff');

        $this->activityLogger->record(
            description: sprintf('%s /%s', $request->getMethod(), ltrim($request->path(), '/')),
            causer: $causer instanceof Model ? $causer : null,
            event: 'mutation',
            properties: [
                'check_in_id' => $outcome->data->checkInId,
                'ticket_id' => $outcome->data->ticketId,
                'event_id' => $outcome->data->eventId,
                'result' => $outcome->data->result->value,
            ],
        );
    }
}
