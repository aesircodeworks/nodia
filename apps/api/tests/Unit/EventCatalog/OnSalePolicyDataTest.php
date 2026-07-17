<?php

use App\EventCatalog\Data\OnSalePolicyData;
use Illuminate\Support\Facades\Validator;

/*
 * Stage-10 plan, Data model "events.on_sale_policy" and TDD Slice 1 (Unit,
 * first): OnSalePolicyData's shape, defaults, and additive-evolution
 * validation, mirroring AsyncPaymentPolicyDataTest's own structure. Pure
 * and DB-free (no model, no cast): constructor defaults and
 * Validator::make() probes against OnSalePolicyData::rules() only.
 */

it('defaults high_demand and challenge_required to false and admission_rate_per_minute to null', function () {
    $policy = new OnSalePolicyData;

    expect($policy->highDemand)->toBeFalse()
        ->and($policy->admissionRatePerMinute)->toBeNull()
        ->and($policy->challengeRequired)->toBeFalse();
});

it('builds from a wire payload supplying all keys', function () {
    $policy = OnSalePolicyData::from([
        'high_demand' => true,
        'admission_rate_per_minute' => 50,
        'challenge_required' => true,
    ]);

    expect($policy->highDemand)->toBeTrue()
        ->and($policy->admissionRatePerMinute)->toBe(50)
        ->and($policy->challengeRequired)->toBeTrue();
});

it('applies its own constructor defaults when a wire payload omits every key', function () {
    $policy = OnSalePolicyData::from([]);

    expect($policy->highDemand)->toBeFalse()
        ->and($policy->admissionRatePerMinute)->toBeNull()
        ->and($policy->challengeRequired)->toBeFalse();
});

it('serializes to snake_case wire keys', function () {
    $policy = new OnSalePolicyData(highDemand: true, admissionRatePerMinute: 50, challengeRequired: true);

    expect($policy->toArray())->toBe([
        'high_demand' => true,
        'admission_rate_per_minute' => 50,
        'challenge_required' => true,
    ]);
});

it('accepts a valid payload under its own validation rules', function () {
    $validator = Validator::make(
        ['high_demand' => true, 'admission_rate_per_minute' => 50, 'challenge_required' => false],
        OnSalePolicyData::rules(),
    );

    expect($validator->fails())->toBeFalse();
});

it('accepts a null admission_rate_per_minute', function () {
    $validator = Validator::make(
        ['high_demand' => false, 'admission_rate_per_minute' => null, 'challenge_required' => false],
        OnSalePolicyData::rules(),
    );

    expect($validator->fails())->toBeFalse();
});

it('rejects a non-boolean high_demand', function () {
    $validator = Validator::make(
        ['high_demand' => 'yes', 'admission_rate_per_minute' => null, 'challenge_required' => false],
        OnSalePolicyData::rules(),
    );

    expect($validator->fails())->toBeTrue();
});

it('rejects an admission_rate_per_minute of zero', function () {
    $validator = Validator::make(
        ['high_demand' => true, 'admission_rate_per_minute' => 0, 'challenge_required' => false],
        OnSalePolicyData::rules(),
    );

    expect($validator->fails())->toBeTrue();
});

it('rejects a negative admission_rate_per_minute', function () {
    $validator = Validator::make(
        ['high_demand' => true, 'admission_rate_per_minute' => -1, 'challenge_required' => false],
        OnSalePolicyData::rules(),
    );

    expect($validator->fails())->toBeTrue();
});

it('rejects a non-integer admission_rate_per_minute', function () {
    $validator = Validator::make(
        ['high_demand' => true, 'admission_rate_per_minute' => 'soon', 'challenge_required' => false],
        OnSalePolicyData::rules(),
    );

    expect($validator->fails())->toBeTrue();
});
