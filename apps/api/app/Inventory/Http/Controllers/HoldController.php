<?php

namespace App\Inventory\Http\Controllers;

use App\Inventory\Actions\CreateHold;
use App\Inventory\Actions\ReleaseHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Data\HoldData;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\Hold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The storefront hold surface (stage-06 plan, Endpoints "Storefront").
 * Guest checkout means no authentication is required; customer_id is
 * derived from an optional customer bearer token, never trusted from the
 * request body (system-design 14.4, CreateHoldData's own docblock).
 * store() also forwards the raw X-Admission-Token header, when present,
 * to App\Inventory\Actions\CreateHold, which decides whether the
 * resolved event actually requires it (stage-10 plan, Endpoints "POST
 * /v1/storefront/holds").
 */
class HoldController
{
    public function store(Request $request, CreateHoldData $data, CreateHold $createHold): JsonResponse
    {
        $customerId = $request->user('customer')?->getAuthIdentifier();
        $admissionToken = $request->headers->get('X-Admission-Token');

        return response()->json($createHold($data, is_string($customerId) ? $customerId : null, $admissionToken), 201);
    }

    public function show(string $hold): HoldData
    {
        $model = $this->holdOrFail($hold);

        // Claim order, not physical row order, so a client that
        // redisplays or re-submits the seat list preserves the buyer's
        // selection sequence, matching HoldForOrderData::fromModel.
        $seatIds = EventSeat::query()
            ->where('hold_id', $model->id)
            ->orderBy('hold_claim_position')
            ->pluck('id')
            ->all();

        return HoldData::fromModel($model, $seatIds);
    }

    /**
     * 204 on release and on repeated release of an already-released or
     * already-expired hold (idempotent DELETE); ReleaseHold itself throws
     * hold_not_found or hold_not_releasable for the other two cases
     * (stage-06 plan, Endpoints "DELETE /v1/storefront/holds/{hold}").
     */
    public function destroy(string $hold, ReleaseHold $releaseHold): Response
    {
        $releaseHold($hold);

        return response()->noContent();
    }

    /**
     * A well-formed but nonexistent or foreign-tenant hold id renders the
     * same hold_not_found problem (RLS makes cross-tenant a natural
     * not-found, stage-06 plan Endpoints).
     */
    private function holdOrFail(string $holdId): Hold
    {
        return Hold::query()->with('items')->find($holdId) ?? throw HoldNotFoundException::forId($holdId);
    }
}
