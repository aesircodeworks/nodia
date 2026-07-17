<?php

namespace App\Tenancy\Exceptions;

use InvalidArgumentException;

final class InvalidGatewayConfigurationException extends InvalidArgumentException
{
    public static function notAListOfNonEmptyStrings(): self
    {
        return new self('Enabled gateways must be a list of non-empty gateway identifiers.');
    }
}
