<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised by a registered-but-unimplemented gateway skeleton (stage-08d
 * plan, Slice 3: PendingGatewayAdapter), and by initiation when the only
 * gateway that could serve a requested method resolves to one. Distinct
 * from GatewayUnavailableException: this is a permanent configuration
 * gap, not a transient transport failure, so it carries no Retry-After.
 */
final class GatewayNotConfiguredException extends RuntimeException implements HasErrorCode
{
    public static function forGateway(string $gateway): self
    {
        return new self("Gateway [{$gateway}] is registered but not configured.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::GatewayNotConfigured;
    }
}
