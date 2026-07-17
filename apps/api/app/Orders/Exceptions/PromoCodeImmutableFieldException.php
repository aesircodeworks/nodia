<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * code, discount_type, discount_value, and currency lock once
 * usage_count > 0: redeemed orders already priced against them
 * (stage-07 plan, Endpoints "PATCH /v1/promo-codes/{promo_code}").
 */
final class PromoCodeImmutableFieldException extends RuntimeException implements HasErrorCode
{
    public static function forField(string $field): self
    {
        return new self(sprintf('The %s of a promo code cannot change once it has been used.', $field));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PromoCodeImmutableField;
    }
}
