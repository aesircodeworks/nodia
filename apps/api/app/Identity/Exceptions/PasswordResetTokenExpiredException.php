<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a password reset confirmation whose token matches a live,
 * unconsumed row, but whose expires_at has passed (stage-03 plan, task
 * breakdown item 16), checked against Date::now() so it honors the Stage
 * 1 fake clock.
 */
final class PasswordResetTokenExpiredException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The password reset token has expired.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PasswordResetTokenExpired;
    }
}
