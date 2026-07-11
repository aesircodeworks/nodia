<?php

namespace App\Inventory\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a hold id that resolves to no visible row: genuinely
 * nonexistent, or a foreign tenant's hold the tenant_isolation RLS policy
 * already hides from a plain find() (stage-06 plan, Endpoints: "GET
 * /v1/storefront/holds/{hold} ... 404 hold_not_found for unknown or
 * cross-tenant IDs").
 */
final class HoldNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $holdId): self
    {
        return new self(sprintf('No hold has id "%s".', $holdId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::HoldNotFound;
    }
}
