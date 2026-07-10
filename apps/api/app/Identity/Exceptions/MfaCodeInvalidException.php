<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when a presented mfa_code (staff token exchange) or code
 * (MFA enrollment confirm and disable) matches neither the current TOTP
 * window nor any unused recovery code (stage-03 plan, MFA endpoint
 * table). Never distinguishes a wrong TOTP code from a wrong or
 * already-used recovery code: both mean "the presented proof of
 * possession is not currently valid", the same no-enumeration posture
 * InvalidCredentialsException already establishes for the password leg.
 */
final class MfaCodeInvalidException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The MFA code is invalid.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MfaCodeInvalid;
    }
}
