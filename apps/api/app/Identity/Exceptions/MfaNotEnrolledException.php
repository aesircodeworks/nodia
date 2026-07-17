<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by POST /v1/auth/mfa/enrollment/confirm and POST
 * /v1/auth/mfa/disable when no enrollment has ever been started
 * (users.mfa_secret is null) (stage-03 plan, MFA endpoint table).
 */
final class MfaNotEnrolledException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('MFA enrollment has not been started for this account.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MfaNotEnrolled;
    }
}
