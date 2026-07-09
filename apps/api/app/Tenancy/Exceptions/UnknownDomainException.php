<?php

namespace App\Tenancy\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use RuntimeException;

final class UnknownDomainException extends RuntimeException implements HasErrorCode
{
    public static function forDomain(string $domain): self
    {
        return new self(sprintf('No tenant has domain "%s" registered.', $domain));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::UnknownDomain;
    }
}
