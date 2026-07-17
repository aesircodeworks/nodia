<?php

namespace App\Tenancy\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class TenantNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $tenantId): self
    {
        return new self(sprintf('No tenant has id "%s".', $tenantId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantNotFound;
    }
}
