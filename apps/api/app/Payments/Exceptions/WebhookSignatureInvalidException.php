<?php

namespace App\Payments\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class WebhookSignatureInvalidException extends RuntimeException implements HasErrorCode
{
    public static function forGateway(string $gateway): self
    {
        return new self("The webhook signature failed verification for the [{$gateway}] gateway.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::WebhookSignatureInvalid;
    }
}
