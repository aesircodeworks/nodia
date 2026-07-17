<?php

namespace App\Support\Money;

use DomainException;

class CurrencyMismatchException extends DomainException
{
    public static function between(string $expected, string $actual): self
    {
        return new self("Cannot operate on money in different currencies [{$expected}] and [{$actual}].");
    }
}
