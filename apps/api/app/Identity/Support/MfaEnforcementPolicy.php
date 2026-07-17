<?php

namespace App\Identity\Support;

use App\Identity\Capability;
use App\Identity\Enums\MembershipScope;

/**
 * A single membership requires confirmed MFA when it is platform-scope,
 * or its role's capability set intersects the financially privileged set
 * (stage-03 plan, MFA enforcement paragraph; system-design 5.1, 5.4).
 * Pure and DB-free so App\Http\Middleware\EnforceMfaCompliance (single
 * acting membership under the asserted tenant) and
 * App\Identity\Actions\HasEnforcingMembership (every membership the
 * caller holds, for the disable endpoint) share exactly one evaluation
 * instead of each re-deriving it.
 */
final class MfaEnforcementPolicy
{
    /**
     * @param  list<string>  $capabilities
     */
    public static function requires(MembershipScope $scope, array $capabilities): bool
    {
        if ($scope === MembershipScope::Platform) {
            return true;
        }

        foreach ($capabilities as $capability) {
            if (Capability::from($capability)->isFinanciallyPrivileged()) {
                return true;
            }
        }

        return false;
    }
}
