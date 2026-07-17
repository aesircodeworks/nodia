<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class SubmerchantNotActiveException extends RuntimeException implements HasErrorCode
{
    public static function forGateway(string $gateway): self
    {
        return new self("Gateway [{$gateway}] has no active sub-merchant account for this tenant.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::SubmerchantNotActive;
    }
}
