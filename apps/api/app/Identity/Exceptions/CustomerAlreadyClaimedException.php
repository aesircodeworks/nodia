<?php

namespace App\Identity\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised when POST /v1/auth/customer/claim/confirm is presented with a
 * valid, unexpired claim token for a customer who already holds a
 * password, whether claimed earlier or created through registration
 * rather than guest checkout (stage-03 plan, task breakdown item 13).
 * This is also what makes replaying a still-valid claim token after its
 * first successful use harmless: App\Identity\Support\ClaimToken itself
 * tracks no single use.
 */
final class CustomerAlreadyClaimedException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('This account has already been claimed.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CustomerAlreadyClaimed;
    }
}
