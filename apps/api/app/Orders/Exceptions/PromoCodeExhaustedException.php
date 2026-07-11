<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * The conditional usage_count increment affected zero rows: the code is
 * at its usage_limit (stage-07 plan, Error codes).
 */
final class PromoCodeExhaustedException extends RuntimeException implements HasErrorCode
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('Promo code "%s" has reached its usage limit.', $code));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PromoCodeExhausted;
    }
}
