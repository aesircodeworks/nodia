<?php

namespace App\Tenancy\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use DomainException;

final class TenantDomainIsPrimaryException extends DomainException implements HasErrorCode
{
    public static function for(string $domain): self
    {
        return new self(sprintf('Domain "%s" is the tenant\'s primary domain; make another domain primary before removing it.', $domain));
    }

    public static function forExistingPrimary(string $domain): self
    {
        return new self(sprintf('Domain "%s" cannot be registered as primary; the tenant already has a primary domain.', $domain));
    }

    public static function forConcurrentPromotion(string $domain): self
    {
        return new self(sprintf('Domain "%s" lost a concurrent promotion; retry if this promotion is still wanted.', $domain));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TenantDomainIsPrimary;
    }
}
