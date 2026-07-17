<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Outside the valid_from/valid_to window (stage-07 plan, Error codes).
 */
final class PromoCodeNotActiveException extends RuntimeException implements HasErrorCode
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('Promo code "%s" is outside its validity window.', $code));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PromoCodeNotActive;
    }
}
