<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class RefundTicketsNotInOrderException extends RuntimeException implements HasErrorCode
{
    public static function forOrder(string $orderId): self
    {
        return new self("One or more requested tickets do not belong to order [{$orderId}].");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RefundTicketsNotInOrder;
    }
}
