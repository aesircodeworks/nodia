<?php

namespace App\Tenancy\Exceptions;

use App\Support\Problems\ErrorCode;
use App\Support\Problems\HasErrorCode;
use InvalidArgumentException;

final class DefaultLocaleNotSupportedException extends InvalidArgumentException implements HasErrorCode
{
    /**
     * @param  list<string>  $supportedLocales
     */
    public static function for(string $defaultLocale, array $supportedLocales): self
    {
        return new self(sprintf(
            'Default locale "%s" is not one of the supported locales [%s].',
            $defaultLocale,
            implode(', ', $supportedLocales),
        ));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::DefaultLocaleNotSupported;
    }
}
