<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by POST /v1/auth/mfa/enrollment when the caller has already
 * confirmed MFA (stage-03 plan, MFA endpoint table). Starting a fresh
 * enrollment before confirming an earlier one is allowed and simply
 * replaces the pending secret; this exception is only for a user who
 * already completed confirmation.
 */
final class MfaAlreadyEnrolledException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('MFA is already enrolled and confirmed for this account.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MfaAlreadyEnrolled;
    }
}
