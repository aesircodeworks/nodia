<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class RefundAmountExceedsRefundableException extends RuntimeException implements HasErrorCode
{
    public static function forPayment(string $paymentId): self
    {
        return new self("The requested amount exceeds what remains refundable on payment [{$paymentId}].");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RefundAmountExceedsRefundable;
    }
}
