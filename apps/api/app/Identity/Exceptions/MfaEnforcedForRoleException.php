<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by POST /v1/auth/mfa/disable when at least one of the caller's
 * memberships is platform-scope or holds a financially privileged
 * capability (stage-03 plan, MFA endpoint table and enforcement
 * paragraph): those memberships mandate confirmed MFA, so disabling it
 * would leave an enforcing membership non-compliant.
 */
final class MfaEnforcedForRoleException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('MFA cannot be disabled while the acting membership requires it.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MfaEnforcedForRole;
    }
}
