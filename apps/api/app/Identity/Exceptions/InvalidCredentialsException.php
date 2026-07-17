<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * The single code for both an unknown email and a correct email with the
 * wrong password (stage-03 plan: no enumeration on the staff token
 * endpoint).
 */
final class InvalidCredentialsException extends RuntimeException implements HasErrorCode
{
    public static function becauseAuthenticationFailed(): self
    {
        return new self('The email or password is incorrect.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidCredentials;
    }
}
