<?php

namespace App\Tenancy\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use DomainException;

final class DomainAlreadyRegisteredException extends DomainException implements HasErrorCode
{
    public static function for(string $domain): self
    {
        return new self(sprintf('Domain "%s" is already registered to a tenant.', $domain));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::DomainAlreadyRegistered;
    }
}
