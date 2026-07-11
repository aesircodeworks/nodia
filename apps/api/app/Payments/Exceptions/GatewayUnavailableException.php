<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class GatewayUnavailableException extends RuntimeException implements HasErrorCode
{
    public static function forGateway(string $gateway): self
    {
        return new self("The [{$gateway}] gateway is temporarily unavailable; retry after the interval in the Retry-After header.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::GatewayUnavailable;
    }
}
