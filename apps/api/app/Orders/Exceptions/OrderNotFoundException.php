<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Missing, other tenant (via RLS), or other customer: all the same
 * not-found (stage-07 plan, Error codes).
 */
final class OrderNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $orderId): self
    {
        return new self(sprintf('No order has id "%s".', $orderId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::OrderNotFound;
    }
}
