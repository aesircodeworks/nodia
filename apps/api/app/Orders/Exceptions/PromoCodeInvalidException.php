<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Unknown code for this tenant; a foreign tenant's code is the same
 * not-found under RLS (stage-07 plan, Error codes).
 */
final class PromoCodeInvalidException extends RuntimeException implements HasErrorCode
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('No promo code "%s" exists for this tenant.', $code));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PromoCodeInvalid;
    }
}
