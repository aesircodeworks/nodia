<?php

namespace App\Support\Tenancy;

use InvalidArgumentException;

final class InvalidTenantIdException extends InvalidArgumentException
{
    public static function for(string $tenantId): self
    {
        return new self(sprintf('Tenant id must be a UUID, got "%s".', $tenantId));
    }
}
