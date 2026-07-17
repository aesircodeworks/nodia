<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for an invitation acceptance token that is malformed, tampered
 * with (Crypt::decryptString fails its MAC check), or names a user id
 * that no longer resolves (stage-03 plan, task breakdown item 9:
 * "invitation acceptance including ... tampered tokens under the fake
 * clock"). Distinct from InvitationTokenExpiredException, whose signature
 * verifies but whose embedded expiry has passed: the two never share a
 * code, mirroring InvalidRefreshTokenException/RefreshTokenReusedException's
 * own precedent for the sibling refresh-token codes.
 */
final class InvitationTokenInvalidException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The invitation token is malformed, tampered with, or unknown.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvitationTokenInvalid;
    }
}
