<?php

use App\Support\Money\Money;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class MoneyProbeData extends Data
{
    public function __construct(
        public Money $price,
    ) {}
}

test('a Data object with a Money property serializes to the {amount, currency} wire shape', function () {
    $data = new MoneyProbeData(Money::of(12500, 'BRL'));

    expect($data->toArray())->toBe([
        'price' => [
            'amount' => 12500,
            'currency' => 'BRL',
        ],
    ]);

    expect($data->toJson())->toBe('{"price":{"amount":12500,"currency":"BRL"}}');
});

test('a Data object hydrates a Money property from the {amount, currency} wire shape', function () {
    $data = MoneyProbeData::from([
        'price' => [
            'amount' => 12500,
            'currency' => 'BRL',
        ],
    ]);

    expect($data->price)->toBeInstanceOf(Money::class)
        ->and($data->price->equals(Money::of(12500, 'BRL')))->toBeTrue();
});

test('a Data object round-trips a Money property through the wire shape', function () {
    $original = new MoneyProbeData(Money::of(-500, 'USD'));

    $hydrated = MoneyProbeData::from($original->toArray());

    expect($hydrated->price->equals($original->price))->toBeTrue();
});
