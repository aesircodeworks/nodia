<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Covers every rejection of a presented refresh token that is not reuse
 * of an already-rotated one: unknown, malformed, expired, tied to a
 * different client, or the loser of a concurrent rotation race
 * (App\Identity\OAuth\IdentityRefreshTokenRepository).
 */
final class InvalidRefreshTokenException extends RuntimeException implements HasErrorCode
{
    public static function becauseTheTokenIsInvalid(): self
    {
        return new self('The refresh token is invalid, expired, or unknown.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidRefreshToken;
    }
}
