<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * An expired hold can never convert to an order, even when the Stage 6
 * sweeper lags and the row still says active (stage-07 plan, Scope;
 * system-design 6.1).
 */
final class HoldExpiredException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $holdId): self
    {
        return new self(sprintf('Hold "%s" has expired and cannot be converted.', $holdId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CheckoutHoldExpired;
    }
}
