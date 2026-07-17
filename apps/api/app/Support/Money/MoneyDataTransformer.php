<?php

namespace App\Support\Money;

use InvalidArgumentException;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

class MoneyDataTransformer implements Transformer
{
    /**
     * @return array{amount: int, currency: string}
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): array
    {
        if (! $value instanceof Money) {
            throw new InvalidArgumentException(sprintf('%s only transforms %s values.', self::class, Money::class));
        }

        return [
            'amount' => $value->amount,
            'currency' => $value->currency,
        ];
    }
}
