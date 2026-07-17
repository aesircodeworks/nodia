<?php

namespace App\Tenancy\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class InvalidTenantHeaderException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The X-Tenant-Id header must be a UUID.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidTenantHeader;
    }
}
