<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use App\Support\Problems\HasProblemHeaders;
use RuntimeException;

final class GatewayUnavailableException extends RuntimeException implements HasErrorCode, HasProblemHeaders
{
    public static function forGateway(string $gateway): self
    {
        return new self("The [{$gateway}] gateway is temporarily unavailable; retry after the interval in the Retry-After header.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::GatewayUnavailable;
    }

    /**
     * @return array<string, string>
     */
    public function problemHeaders(): array
    {
        return ['Retry-After' => (string) config('payments.gateway_retry_after_seconds')];
    }
}
