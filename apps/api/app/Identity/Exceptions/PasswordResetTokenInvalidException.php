<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a password reset confirmation whose token is unknown,
 * malformed, or already consumed (stage-03 plan, task breakdown item 16).
 * Distinct from PasswordResetTokenExpiredException, whose token matches a
 * live, unconsumed row but whose expiry has passed: the two never share a
 * code, mirroring ClaimTokenInvalidException/ClaimTokenExpiredException's
 * own precedent for the sibling single-use token. A replayed,
 * already-consumed token renders identically to a token that never
 * existed, so the response never reveals which case applies.
 */
final class PasswordResetTokenInvalidException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The password reset token is unknown, malformed, or already used.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PasswordResetTokenInvalid;
    }
}
