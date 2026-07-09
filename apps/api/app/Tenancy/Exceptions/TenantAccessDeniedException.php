<?php

namespace App\Tenancy\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

/**
 * Raised for an X-Tenant-Id that resolves to no accessible tenant. The
 * message is deliberately the same whether the tenant does not exist or
 * (from Stage 3 on) the caller lacks a membership in it, so activating
 * membership checks never changes the wire contract and the response
 * never discloses which tenants exist.
 */
final class TenantAccessDeniedException extends RuntimeException implements HasErrorCode
{
    public static function forTenant(string $tenantId): self
    {
        return new self(sprintf('You do not have access to tenant "%s".', $tenantId));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantAccessDenied;
    }
}
