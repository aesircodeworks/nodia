<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a membership id that resolves to no visible row in the
 * asserted tenant: genuinely nonexistent, or a foreign tenant's
 * membership the base tenant_isolation policy already hides from a
 * tenant-scoped find() (stage-03 plan, Slice 4 isolation denial probes),
 * mirroring App\Identity\Exceptions\RoleNotFoundException's own
 * precedent. Maps to the generic request.not_found code, not a
 * membership-specific one, matching the endpoint table.
 */
final class MembershipNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $membershipId): self
    {
        return new self(sprintf('No membership has id "%s" in the acting tenant.', $membershipId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
