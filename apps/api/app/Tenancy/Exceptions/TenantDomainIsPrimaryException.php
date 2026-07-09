<?php

namespace App\Tenancy\Exceptions;

use DomainException;

final class TenantDomainIsPrimaryException extends DomainException
{
    public static function for(string $domain): self
    {
        return new self(sprintf('Domain "%s" is the tenant\'s primary domain; make another domain primary before removing it.', $domain));
    }
}
