<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by IssueStaffToken when the credentials are correct, the user
 * has confirmed MFA, and no mfa_code was supplied (stage-03 plan, Staff
 * authentication endpoint table). Distinct from MfaCodeInvalidException
 * so a client can tell "no code was tried yet" from "the code was wrong"
 * even though both share a 401 status.
 */
final class MfaRequiredException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('This account has MFA enabled; a valid TOTP or recovery code is required.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MfaRequired;
    }
}
