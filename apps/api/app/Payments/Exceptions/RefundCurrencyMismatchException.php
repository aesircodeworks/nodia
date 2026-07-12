<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class RefundCurrencyMismatchException extends RuntimeException implements HasErrorCode
{
    public static function forPayment(string $paymentId): self
    {
        return new self("The refund currency must equal the currency of payment [{$paymentId}].");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RefundCurrencyMismatch;
    }
}
