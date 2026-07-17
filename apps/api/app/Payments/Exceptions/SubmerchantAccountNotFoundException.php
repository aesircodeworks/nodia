<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a submerchant_account id that resolves to no visible row:
 * genuinely nonexistent, or a foreign tenant's row that
 * tenant_isolation RLS already hides from a plain find() (stage-08c
 * plan, Endpoints: "404 request.not_found ... RLS makes the cross-tenant
 * case indistinguishable from absence"). Deliberately maps to the
 * generic request.not_found code, mirroring
 * App\Identity\Exceptions\RoleNotFoundException.
 */
final class SubmerchantAccountNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $submerchantAccountId): self
    {
        return new self("No submerchant account has id [{$submerchantAccountId}].");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
