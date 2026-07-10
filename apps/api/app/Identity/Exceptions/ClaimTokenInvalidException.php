<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a customer claim token that is malformed, tampered with
 * (Crypt::decryptString fails its MAC check), or names a customer id
 * that no longer resolves under this request's asserted tenant
 * (stage-03 plan, task breakdown item 13), mirroring
 * App\Identity\Exceptions\InvitationTokenInvalidException's own
 * precedent for the sibling staff invitation flow. Distinct from
 * ClaimTokenExpiredException, whose signature verifies but whose
 * embedded expiry has passed.
 */
final class ClaimTokenInvalidException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The claim token is malformed, tampered with, or unknown.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ClaimTokenInvalid;
    }
}
