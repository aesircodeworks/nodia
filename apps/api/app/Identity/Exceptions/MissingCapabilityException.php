<?php

namespace App\Identity\Exceptions;

use App\Identity\Capability;
use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by CapabilityGate when the acting membership's role does not
 * carry the required capability (system-design 5.3, ADR 012). Always
 * evaluated as capability plus tenant context, never role names, so the
 * message names only the capability, never the role that was missing it.
 */
final class MissingCapabilityException extends RuntimeException implements HasErrorCode
{
    public static function for(Capability $capability): self
    {
        return new self(sprintf('The "%s" capability is required to perform this action.', $capability->value));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MissingCapability;
    }
}
