<?php

namespace App\CheckIn\Actions;

use App\CheckIn\Data\CheckInAssignmentData;
use App\CheckIn\Exceptions\AlreadyAssignedException;
use App\CheckIn\Exceptions\UserNotMemberException;
use App\CheckIn\Models\CheckInAssignment;
use App\Identity\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /v1/events/{event}/check-in-assignments (stage-09 plan, Endpoints
 * "Check-in assignments"). The target user must already hold a
 * membership in the acting tenant (422 user_not_member); the insert
 * itself is guarded by the unique (tenant_id, event_id, user_id) index
 * rather than a prior existence read, so a race between two identical
 * requests still surfaces 409 already_assigned deterministically.
 */
final class AssignCheckInUser
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function __invoke(string $eventId, string $userId): CheckInAssignmentData
    {
        $tenantId = $this->tenantContext->tenantId();

        $isMember = Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->exists();

        if (! $isMember) {
            throw UserNotMemberException::forUser($userId);
        }

        try {
            $assignment = CheckInAssignment::create([
                'tenant_id' => $tenantId,
                'event_id' => $eventId,
                'user_id' => $userId,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'check_in_assignments_tenant_id_event_id_user_id_unique')) {
                throw AlreadyAssignedException::for($eventId, $userId);
            }

            throw $e;
        }

        return CheckInAssignmentData::fromModel($assignment);
    }
}
