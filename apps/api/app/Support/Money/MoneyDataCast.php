<?php

namespace App\Support\Money;

use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Casts\Uncastable;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;

class MoneyDataCast implements Cast
{
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): Money|Uncastable
    {
        if ($value instanceof Money) {
            return $value;
        }

        if (is_array($value) && is_int($value['amount'] ?? null) && is_string($value['currency'] ?? null)) {
            return Money::of($value['amount'], $value['currency']);
        }

        return Uncastable::create();
    }
}
