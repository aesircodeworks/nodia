<?php

namespace App\Orders\Http\Controllers;

use App\CheckIn\Actions\CheckEventAssignment;
use App\CheckIn\Data\CheckEventAssignmentData;
use App\CheckIn\Exceptions\CheckinNotAssignedException;
use App\EventCatalog\Actions\CheckEventExists;
use App\Identity\Actions\ResolveActingCapabilities;
use App\Orders\Actions\RotateSigningKey;
use App\Orders\Data\RotateSigningKeyData;
use App\Orders\Data\SigningKeyData;
use App\Orders\Data\SigningKeyListData;
use App\Orders\Enums\SigningKeyStatus;
use App\Orders\Exceptions\SigningKeyEventNotFoundException;
use App\Orders\Models\EventSigningKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff signing-key surface (stage-09 plan, Endpoints "GET/POST
 * /v1/events/{event}/signing-keys"). GET authorizes through
 * checkin.scan plus an assignment row for the event, or checkin.manage
 * as a bypass, evaluated by the CheckIn context's own
 * CheckEventAssignment Action so this Orders controller never imports
 * CheckIn models or queries check_in_assignments directly (boundary
 * rule, system-design 3.1). POST requires checkin.manage alone, enforced
 * by the RequireCapability route middleware, mirroring
 * PromoCodeAdminController's own split between a custom-authorized GET
 * and a plain capability-gated mutation.
 */
class SigningKeyController
{
    public function __construct(
        private readonly CheckEventExists $checkEventExists,
        private readonly ResolveActingCapabilities $resolveCapabilities,
        private readonly CheckEventAssignment $checkEventAssignment,
    ) {}

    public function index(string $event, Request $request): SigningKeyListData
    {
        $eventId = $this->eventIdOrFail($event);
        $this->authorizeForEvent($request, $eventId);

        $keys = EventSigningKey::query()
            ->where('event_id', $eventId)
            ->where('status', '!=', SigningKeyStatus::Revoked)
            ->orderBy('key_version')
            ->get();

        return SigningKeyListData::fromModels($keys);
    }

    public function store(string $event, RotateSigningKeyData $data, RotateSigningKey $rotateSigningKey): JsonResponse
    {
        $eventId = $this->eventIdOrFail($event);

        $key = $rotateSigningKey($eventId, $data->revokePreviousOrDefault());

        return response()->json(SigningKeyData::fromModel($key), 201);
    }

    /**
     * A well-formed but nonexistent or foreign-tenant (via RLS) event id
     * renders event_not_found, mirroring EventSeatController's own
     * eventIdOrFail precedent.
     */
    private function eventIdOrFail(string $eventId): string
    {
        if (! ($this->checkEventExists)($eventId)) {
            throw SigningKeyEventNotFoundException::forId($eventId);
        }

        return $eventId;
    }

    private function authorizeForEvent(Request $request, string $eventId): void
    {
        $userId = $request->user('staff')?->getAuthIdentifier();
        $capabilities = $userId === null ? [] : ($this->resolveCapabilities)($userId);

        $result = ($this->checkEventAssignment)(new CheckEventAssignmentData(
            userId: (string) $userId,
            eventId: $eventId,
            capabilities: $capabilities,
        ));

        if (! $result->authorized) {
            throw CheckinNotAssignedException::forEvent($eventId);
        }
    }
}
