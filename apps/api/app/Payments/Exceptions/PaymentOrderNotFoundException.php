<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * The payment surface renders the generic request.not_found for a
 * missing, cross-tenant, or foreign-customer order (stage-08a plan,
 * Endpoints), so existence never leaks.
 */
final class PaymentOrderNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forOrder(string $orderId): self
    {
        return new self("No payable order [{$orderId}] is visible to this caller.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RequestNotFound;
    }
}
