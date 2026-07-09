<?php

namespace App\Tenancy\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class MissingTenantHeaderException extends RuntimeException implements HasErrorCode
{
    public static function make(): self
    {
        return new self('The request must carry the acting tenant in the X-Tenant-Id header.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::MissingTenantHeader;
    }
}
