<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when a role's capabilities list carries a name outside the
 * Capability registry (stage-03 plan, Roles and memberships endpoint
 * table). Distinct from request.validation_failed: the payload shape is
 * valid (a list of strings), but a value in it is not a real capability,
 * a domain-level check App\Identity\Models\Role::assertKnownCapabilities()
 * performs, not laravel-data request validation.
 */
final class UnknownCapabilityException extends RuntimeException implements HasErrorCode
{
    public static function for(string $capability): self
    {
        return new self(sprintf('"%s" is not a known capability.', $capability));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::UnknownCapability;
    }
}
