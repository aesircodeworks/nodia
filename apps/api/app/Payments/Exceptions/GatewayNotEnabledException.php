<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class GatewayNotEnabledException extends RuntimeException implements HasErrorCode
{
    public static function forGateway(string $gateway): self
    {
        return new self("Gateway [{$gateway}] is not in the tenant's enabled_gateways.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::GatewayNotEnabled;
    }
}
