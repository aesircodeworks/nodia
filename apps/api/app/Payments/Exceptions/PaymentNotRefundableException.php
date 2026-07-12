<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class PaymentNotRefundableException extends RuntimeException implements HasErrorCode
{
    public static function forPayment(string $paymentId): self
    {
        return new self("Payment [{$paymentId}] is not in a refundable state.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PaymentNotRefundable;
    }
}
