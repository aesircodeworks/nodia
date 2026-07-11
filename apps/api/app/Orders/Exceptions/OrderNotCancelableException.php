<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Cancel attempted on a non-pending order: system-design 7.1 draws
 * canceled only out of pending (stage-07 plan, Error codes).
 */
final class OrderNotCancelableException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $orderId): self
    {
        return new self(sprintf('Order "%s" is not pending and cannot be canceled.', $orderId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::OrderNotCancelable;
    }
}
