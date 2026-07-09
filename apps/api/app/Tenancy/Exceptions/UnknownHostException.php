<?php

namespace App\Tenancy\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class UnknownHostException extends RuntimeException implements HasErrorCode
{
    public static function forHost(string $host): self
    {
        return new self(sprintf('No tenant serves host "%s".', $host));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::UnknownHost;
    }
}
