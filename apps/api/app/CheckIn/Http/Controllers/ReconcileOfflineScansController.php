<?php

namespace App\CheckIn\Http\Controllers;

use App\CheckIn\Actions\ReconcileOfflineScans;
use App\CheckIn\Data\ReconcileBatchData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /v1/check-in-batches (stage-09 plan, Endpoints "POST
 * /v1/check-in-batches", Slice 5). Always 200: per-scan outcomes never
 * fail the batch wholesale. Authorization is per-scan inside
 * App\CheckIn\Actions\ReconcileOfflineScans, since each scan's target
 * event is only known once its QR payload has verified, mirroring
 * App\CheckIn\Http\Controllers\RecordScanController's own posture for
 * the single-scan endpoint.
 */
class ReconcileOfflineScansController
{
    public function __construct(private readonly ReconcileOfflineScans $reconcile) {}

    public function store(ReconcileBatchData $data, Request $request): JsonResponse
    {
        $userId = (string) $request->user('staff')?->getAuthIdentifier();

        $result = ($this->reconcile)($data, $userId);

        return response()->json($result, 200);
    }
}
