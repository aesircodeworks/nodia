<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class RefundPaymentNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forPayment(string $paymentId): self
    {
        return new self("Payment [{$paymentId}] was not found for refunding.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RefundPaymentNotFound;
    }
}
