<?php

use App\EventCatalog\Data\AsyncPaymentPolicyData;
use Illuminate\Support\Facades\Validator;

/*
 * Stage-05a plan, task breakdown item 4 and Data model: events.
 * async_payment_policy's shape. Pure and DB-free (no model, no cast):
 * constructor defaults and Validator::make() probes against
 * AsyncPaymentPolicyData::rules() only.
 */

it('defaults slow_methods_enabled to true and low_inventory_cutoff to null', function () {
    $policy = new AsyncPaymentPolicyData;

    expect($policy->slowMethodsEnabled)->toBeTrue()
        ->and($policy->lowInventoryCutoff)->toBeNull();
});

it('builds from a wire payload supplying both keys', function () {
    $policy = AsyncPaymentPolicyData::from([
        'slow_methods_enabled' => false,
        'low_inventory_cutoff' => 25,
    ]);

    expect($policy->slowMethodsEnabled)->toBeFalse()
        ->and($policy->lowInventoryCutoff)->toBe(25);
});

it('applies its own constructor defaults when a wire payload omits both keys', function () {
    $policy = AsyncPaymentPolicyData::from([]);

    expect($policy->slowMethodsEnabled)->toBeTrue()
        ->and($policy->lowInventoryCutoff)->toBeNull();
});

it('serializes to snake_case wire keys', function () {
    $policy = new AsyncPaymentPolicyData(slowMethodsEnabled: false, lowInventoryCutoff: 5);

    expect($policy->toArray())->toBe([
        'slow_methods_enabled' => false,
        'low_inventory_cutoff' => 5,
    ]);
});

it('accepts a valid payload under its own validation rules', function () {
    $validator = Validator::make(
        ['slow_methods_enabled' => true, 'low_inventory_cutoff' => 10],
        AsyncPaymentPolicyData::rules(),
    );

    expect($validator->fails())->toBeFalse();
});

it('accepts a null low_inventory_cutoff', function () {
    $validator = Validator::make(
        ['slow_methods_enabled' => true, 'low_inventory_cutoff' => null],
        AsyncPaymentPolicyData::rules(),
    );

    expect($validator->fails())->toBeFalse();
});

it('rejects a non-boolean slow_methods_enabled', function () {
    $validator = Validator::make(
        ['slow_methods_enabled' => 'yes', 'low_inventory_cutoff' => null],
        AsyncPaymentPolicyData::rules(),
    );

    expect($validator->fails())->toBeTrue();
});

it('rejects a negative low_inventory_cutoff', function () {
    $validator = Validator::make(
        ['slow_methods_enabled' => true, 'low_inventory_cutoff' => -1],
        AsyncPaymentPolicyData::rules(),
    );

    expect($validator->fails())->toBeTrue();
});

it('rejects a non-integer low_inventory_cutoff', function () {
    $validator = Validator::make(
        ['slow_methods_enabled' => true, 'low_inventory_cutoff' => 'soon'],
        AsyncPaymentPolicyData::rules(),
    );

    expect($validator->fails())->toBeTrue();
});
