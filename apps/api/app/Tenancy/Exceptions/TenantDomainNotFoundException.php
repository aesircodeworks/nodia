<?php

namespace App\Tenancy\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class TenantDomainNotFoundException extends RuntimeException implements HasErrorCode
{
    public static function forId(string $tenantDomainId): self
    {
        return new self(sprintf('No tenant domain has id "%s".', $tenantDomainId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantDomainNotFound;
    }
}
