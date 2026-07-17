<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * A fixed-amount code whose currency differs from the order currency
 * (stage-07 plan, Error codes; data-conventions Money).
 */
final class PromoCodeCurrencyMismatchException extends RuntimeException implements HasErrorCode
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('Promo code "%s" does not match the order currency.', $code));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PromoCodeCurrencyMismatch;
    }
}
