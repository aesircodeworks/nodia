<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class RefundNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $refundId): self
    {
        return new self("Refund [{$refundId}] was not found.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RefundNotFound;
    }
}
