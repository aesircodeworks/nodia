<?php

namespace App\Tenancy\Exceptions;

use InvalidArgumentException;

final class DefaultLocaleNotSupportedException extends InvalidArgumentException
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
}
