<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a customer id that resolves to no visible row: genuinely
 * nonexistent, or another tenant's customer that RLS already hides from a
 * plain find() (stage-12 plan, Endpoints: "404 for a customer of another
 * tenant (RLS makes this structural)"). Maps to the generic
 * request.not_found code, not a customer-specific one, mirroring
 * App\Identity\Exceptions\RoleNotFoundException and
 * MembershipNotFoundException's own precedent.
 */
final class CustomerNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $customerId): self
    {
        return new self(sprintf('No customer has id "%s" in the acting tenant.', $customerId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
