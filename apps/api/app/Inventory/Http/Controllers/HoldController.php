<?php

namespace App\Inventory\Http\Controllers;

use App\Inventory\Actions\CreateHold;
use App\Inventory\Data\CreateHoldData;
use App\Inventory\Data\HoldData;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Inventory\Models\Hold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The storefront hold surface (stage-06 plan, Endpoints "Storefront").
 * Guest checkout means no authentication is required; customer_id is
 * derived from an optional customer bearer token, never trusted from the
 * request body (system-design 14.4, CreateHoldData's own docblock).
 */
class HoldController
{
    public function store(Request $request, CreateHoldData $data, CreateHold $createHold): JsonResponse
    {
        $customerId = $request->user('customer')?->getAuthIdentifier();

        return response()->json($createHold($data, is_string($customerId) ? $customerId : null), 201);
    }

    public function show(string $hold): HoldData
    {
        return HoldData::fromModel($this->holdOrFail($hold));
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
