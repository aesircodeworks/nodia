<?php

namespace App\CheckIn\Policies;

use App\Identity\Capability;

/**
 * Pure, DB-free evaluation of the stage-09 plan's authorization semantics
 * paragraph: manifest, keys, scan, and batch endpoints require checkin.scan
 * plus an assignment row for the target event; a role holding checkin.manage
 * bypasses the assignment requirement entirely. Evaluated on capabilities
 * alone, never role names (system-design 5.3). The caller (CheckEventAssignment
 * for cross-context use, and this context's own controllers directly)
 * resolves the assignment row's existence and passes it in as $assigned;
 * this class only decides what to do with it, mirroring
 * App\Identity\Support\MfaEnforcementPolicy's pure-evaluator precedent.
 */
final class CheckInAssignmentPolicy
{
    /**
     * @param  list<string>  $capabilities
     */
    public static function allows(array $capabilities, bool $assigned): bool
    {
        if (in_array(Capability::CheckinManage->value, $capabilities, true)) {
            return true;
        }

        if (! in_array(Capability::CheckinScan->value, $capabilities, true)) {
            return false;
        }

        return $assigned;
    }
}
