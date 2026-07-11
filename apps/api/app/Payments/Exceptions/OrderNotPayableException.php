<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class OrderNotPayableException extends RuntimeException implements HasErrorCode
{
    public static function forOrder(string $orderId): self
    {
        return new self("Order [{$orderId}] is not in a state that accepts payment.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::OrderNotPayable;
    }
}
