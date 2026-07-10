<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by the EnforceMfaCompliance middleware for every request inside
 * the tenancy.admin and tenancy.platform groups when the acting
 * membership is platform-scope, or its role holds a financially
 * privileged capability, and the caller has not confirmed MFA
 * (stage-03 plan, MFA enforcement paragraph, system-design 5.1, 14.2).
 * The auth and MFA-enrollment endpoints never carry this middleware, so
 * an enforcing but unconfirmed caller can always reach enrollment.
 */
final class MfaEnforcementRequiredException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('This membership requires confirmed MFA before it can perform this action.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MfaEnforcementRequired;
    }
}
