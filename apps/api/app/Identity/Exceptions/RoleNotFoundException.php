<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a role id that resolves to no visible row: genuinely
 * nonexistent, or a foreign tenant's custom role that
 * roles_template_or_tenant_read RLS already hides from a plain find()
 * (stage-03 plan, Slice 4 isolation denial probes: "a foreign-tenant role
 * ID in the URL is 404 not 403, so existence does not leak"). Deliberately
 * maps to the generic request.not_found code rather than a role-specific
 * one, unlike App\Tenancy\Exceptions\TenantNotFoundException: the stage
 * plan's endpoint table lists request.not_found for this row, and the two
 * causes above render identically on purpose.
 */
final class RoleNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $roleId): self
    {
        return new self(sprintf('No role has id "%s".', $roleId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
