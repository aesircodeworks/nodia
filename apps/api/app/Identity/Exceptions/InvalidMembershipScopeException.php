<?php

namespace App\Identity\Exceptions;

use InvalidArgumentException;

/**
 * Guards the application invariant that a platform-scope membership's
 * tenant_id is always the sentinel platform tenant, never any other
 * tenant (stage-03 plan, Data model). A caller hitting this has a bug, not
 * bad user input, so this never implements HasErrorCode: it is not meant
 * to reach the HTTP boundary, mirroring App\Support\Tenancy's
 * InvalidTenantIdException.
 */
final class InvalidMembershipScopeException extends InvalidArgumentException
{
    public static function forNonSentinelTenant(string $tenantId): self
    {
        return new self(sprintf(
            'A platform-scope membership must use the sentinel platform tenant, got "%s".',
            $tenantId,
        ));
    }
}
