<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class PaymentMethodNotAvailableException extends RuntimeException implements HasErrorCode
{
    public static function forMethod(string $method): self
    {
        return new self("The [{$method}] payment method is not currently offered for this order.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PaymentMethodNotAvailable;
    }
}
