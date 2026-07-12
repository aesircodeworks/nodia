<?php

namespace App\CheckIn\Http\Controllers;

use App\CheckIn\Actions\AssignCheckInUser;
use App\CheckIn\Data\AssignCheckInUserData;
use App\CheckIn\Data\CheckInAssignmentData;
use App\CheckIn\Exceptions\AssignmentNotFoundException;
use App\CheckIn\Exceptions\CheckInAssignmentEventNotFoundException;
use App\CheckIn\Models\CheckInAssignment;
use App\EventCatalog\Actions\CheckEventExists;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The staff check-in assignment surface (stage-09 plan, Endpoints
 * "Check-in assignments" and Slice 6), all behind checkin.manage
 * (enforced by the RequireCapability route middleware) and audited.
 * GET is page-paginated (bounded per-event collection); POST and DELETE
 * mirror MembershipController's own capability-gated CRUD shape. DELETE
 * is a top-level resource (no {event} in its path, api-conventions
 * URLs) scoped by RLS alone, mirroring how PromoCodeAdminController's
 * own tenant-scoped find() precedent already relies on RLS rather than
 * an explicit tenant_id filter for id-keyed lookups.
 */
class CheckInAssignmentController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CheckEventExists $checkEventExists,
    ) {}

    /**
     * @return PaginatedDataCollection<int, CheckInAssignmentData>
     */
    public function index(string $event, Request $request): PaginatedDataCollection
    {
        $eventId = $this->eventIdOrFail($event);

        $assignments = CheckInAssignment::query()
            ->where('event_id', $eventId)
            ->orderBy('created_at')
            ->paginate()
            ->appends($request->query());

        return CheckInAssignmentData::collect($assignments, PaginatedDataCollection::class);
    }

    public function store(string $event, AssignCheckInUserData $data, AssignCheckInUser $assignCheckInUser): JsonResponse
    {
        $eventId = $this->eventIdOrFail($event);

        return response()->json($assignCheckInUser($eventId, $data->userId), 201);
    }

    public function destroy(string $assignment): Response
    {
        $this->assignmentOrFail($assignment)->delete();

        return response()->noContent();
    }

    private function eventIdOrFail(string $eventId): string
    {
        if (! ($this->checkEventExists)($eventId)) {
            throw CheckInAssignmentEventNotFoundException::forId($eventId);
        }

        return $eventId;
    }

    private function assignmentOrFail(string $assignmentId): CheckInAssignment
    {
        return CheckInAssignment::query()
            ->where('tenant_id', $this->tenantContext->tenantId())
            ->find($assignmentId) ?? throw AssignmentNotFoundException::forId($assignmentId);
    }
}
