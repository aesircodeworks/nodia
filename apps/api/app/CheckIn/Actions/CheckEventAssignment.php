<?php

namespace App\CheckIn\Actions;

use App\CheckIn\Data\CheckEventAssignmentData;
use App\CheckIn\Data\EventAssignmentData;
use App\CheckIn\Models\CheckInAssignment;
use App\CheckIn\Policies\CheckInAssignmentPolicy;
use App\Identity\Capability;

/**
 * The sanctioned Action other contexts call to evaluate the stage-09 plan's
 * authorization semantics (checkin.scan plus assignment, checkin.manage
 * bypass) without importing CheckIn models or querying check_in_assignments
 * directly (boundary rule, system-design 3.1). Orders' signing-key
 * endpoints are the first consumer. Short-circuits on checkin.manage
 * before touching the database, since a manage-holding caller is
 * authorized regardless of any assignment row.
 */
final class CheckEventAssignment
{
    public function __invoke(CheckEventAssignmentData $data): EventAssignmentData
    {
        if (in_array(Capability::CheckinManage->value, $data->capabilities, true)) {
            return new EventAssignmentData(authorized: true);
        }

        $assigned = CheckInAssignment::query()
            ->where('event_id', $data->eventId)
            ->where('user_id', $data->userId)
            ->exists();

        return new EventAssignmentData(
            authorized: CheckInAssignmentPolicy::allows($data->capabilities, $assigned),
        );
    }
}
