<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for a customer claim token whose signature verifies but whose
 * embedded expiry has passed, checked against Date::now() so it honors
 * the Stage 1 fake clock (stage-03 plan, task breakdown item 13).
 */
final class ClaimTokenExpiredException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The claim token has expired.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ClaimTokenExpired;
    }
}
