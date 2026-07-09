<?php

declare(strict_types=1);

use App\Support\Money\CurrencyMismatchException;
use App\Support\Money\Money;

test('constructs from integer minor units and an uppercase ISO 4217 code', function () {
    $money = Money::of(12500, 'BRL');

    expect($money->amount)->toBe(12500)
        ->and($money->currency)->toBe('BRL');
});

test('rejects invalid currency codes', function (string $currency) {
    Money::of(100, $currency);
})->with([
    'lowercase' => 'brl',
    'too short' => 'BR',
    'too long' => 'BRLX',
    'digits' => 'B1L',
    'empty' => '',
    'mixed case' => 'Brl',
])->throws(InvalidArgumentException::class);

test('equals compares amount and currency', function () {
    $money = Money::of(12500, 'BRL');

    expect($money->equals(Money::of(12500, 'BRL')))->toBeTrue()
        ->and($money->equals(Money::of(12501, 'BRL')))->toBeFalse()
        ->and($money->equals(Money::of(12500, 'USD')))->toBeFalse();
});

test('compares amounts within the same currency', function () {
    $smaller = Money::of(100, 'BRL');
    $larger = Money::of(200, 'BRL');

    expect($larger->greaterThan($smaller))->toBeTrue()
        ->and($smaller->greaterThan($larger))->toBeFalse()
        ->and($smaller->lessThan($larger))->toBeTrue()
        ->and($larger->lessThan($smaller))->toBeFalse();
});

test('comparison across currencies throws CurrencyMismatchException', function () {
    Money::of(100, 'BRL')->greaterThan(Money::of(100, 'USD'));
})->throws(CurrencyMismatchException::class);

test('adds and subtracts within the same currency', function () {
    $result = Money::of(10000, 'BRL')->add(Money::of(2500, 'BRL'));

    expect($result->equals(Money::of(12500, 'BRL')))->toBeTrue();

    $result = Money::of(10000, 'BRL')->subtract(Money::of(2500, 'BRL'));

    expect($result->equals(Money::of(7500, 'BRL')))->toBeTrue();
});

test('add across currencies throws CurrencyMismatchException', function () {
    Money::of(100, 'BRL')->add(Money::of(100, 'USD'));
})->throws(CurrencyMismatchException::class);

test('subtract across currencies throws CurrencyMismatchException', function () {
    Money::of(100, 'BRL')->subtract(Money::of(100, 'USD'));
})->throws(CurrencyMismatchException::class);

test('multiplies by an integer factor', function () {
    $result = Money::of(2500, 'BRL')->multiplyBy(3);

    expect($result->equals(Money::of(7500, 'BRL')))->toBeTrue();
});

test('multiplication by a float is a type error', function () {
    /** @phpstan-ignore argument.type (proves the int-only signature rejects floats under strict types) */
    Money::of(2500, 'BRL')->multiplyBy(1.5);
})->throws(TypeError::class);

test('negative amounts are allowed', function () {
    $refund = Money::of(-500, 'BRL');

    expect($refund->amount)->toBe(-500)
        ->and($refund->isNegative())->toBeTrue()
        ->and(Money::of(500, 'BRL')->isNegative())->toBeFalse();

    $balance = Money::of(100, 'BRL')->subtract(Money::of(300, 'BRL'));

    expect($balance->amount)->toBe(-200);
});

test('no float ever enters the object', function () {
    /** @phpstan-ignore argument.type (proves the int-only signature rejects floats under strict types) */
    Money::of(125.5, 'BRL');
})->throws(TypeError::class);

test('no float ever leaves the object', function () {
    $money = Money::of(12500, 'BRL')->multiplyBy(2)->add(Money::of(1, 'BRL'));

    expect($money->amount)->toBeInt();
});
