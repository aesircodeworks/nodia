<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class GatewayUnknownException extends RuntimeException implements HasErrorCode
{
    public static function forGateway(string $gateway): self
    {
        return new self("No adapter is registered for gateway [{$gateway}].");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::GatewayUnknown;
    }
}
