<?php

namespace App\Orders\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Resend-tickets on an order without issued tickets (stage-07 plan,
 * Error codes).
 */
final class OrderNotPaidException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $orderId): self
    {
        return new self(sprintf('Order "%s" has no issued tickets to resend.', $orderId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::OrderNotPaid;
    }
}
