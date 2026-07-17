<?php

use App\Payments\Enums\RefundCommissionPolicy;
use App\Payments\Support\CommissionCalculator;
use App\Support\Money\Money;

it('exposes exactly the two documented refund commission policies', function () {
    expect(array_map(fn (RefundCommissionPolicy $case) => $case->value, RefundCommissionPolicy::cases()))
        ->toBe(['returned', 'retained']);
});

it('computes the commission as basis points of gross in integer minor units', function () {
    $calculator = new CommissionCalculator;

    $commission = $calculator->bpsOf(Money::of(10_000, 'BRL'), 250);

    expect($commission->amount)->toBe(250)
        ->and($commission->currency)->toBe('BRL');
});

it('rounds half up at the minor-unit boundary', function (int $gross, int $bps, int $expected) {
    $commission = (new CommissionCalculator)->bpsOf(Money::of($gross, 'BRL'), $bps);

    expect($commission->amount)->toBe($expected);
})->with([
    'exact half rounds up' => [2, 2500, 1],
    'just below half rounds down' => [999, 25, 2],
    'just above half rounds up' => [1_001, 25, 3],
    'zero bps yields zero' => [10_000, 0, 0],
    'zero gross yields zero' => [0, 250, 0],
    'full ten thousand bps is identity' => [12_345, 10_000, 12_345],
    'one minor unit at one bps rounds down' => [1, 1, 0],
    'half exactly at scale' => [5, 1_000, 1],
]);

it('preserves the gross currency', function () {
    $commission = (new CommissionCalculator)->bpsOf(Money::of(777, 'USD'), 100);

    expect($commission->currency)->toBe('USD');
});
