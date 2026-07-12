<?php

namespace App\CheckIn\Http\Controllers;

use App\CheckIn\Actions\RecordScan;
use App\CheckIn\Data\RecordScanData;
use App\Support\Problems\ErrorCode;
use App\Support\Problems\ProblemData;
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
 */
class RecordScanController
{
    public function __construct(private readonly RecordScan $recordScan) {}

    public function store(RecordScanData $data, Request $request): JsonResponse
    {
        $userId = (string) $request->user('staff')?->getAuthIdentifier();

        $outcome = ($this->recordScan)($data, $userId);

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
}
