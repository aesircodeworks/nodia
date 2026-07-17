<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when a refresh token that was already rotated away from (or
 * otherwise revoked) is presented again. By the time this exception is
 * thrown, App\Identity\OAuth\IdentityRefreshTokenRepository has already
 * revoked every live token in the token's family.
 */
final class RefreshTokenReusedException extends RuntimeException implements HasErrorCode
{
    public static function becauseTheFamilyWasRevoked(): self
    {
        return new self('This refresh token was already used; every token issued from its login has been revoked.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RefreshTokenReused;
    }
}
