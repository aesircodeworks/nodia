<?php

namespace App\Tenancy\Exceptions;

use InvalidArgumentException;

final class InvalidDomainNameException extends InvalidArgumentException
{
    public static function for(string $domain): self
    {
        return new self(sprintf('"%s" is not a valid hostname.', $domain));
    }
}
