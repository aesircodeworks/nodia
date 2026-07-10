<?php

use App\EventCatalog\Data\CreateTicketTypeData;
use App\EventCatalog\Data\UpdateTicketTypeData;
use Illuminate\Support\Facades\Validator;

/*
 * Stage-05a plan, task breakdown item 8: pure, DB-free coverage of
 * CreateTicketTypeData/UpdateTicketTypeData's rules() and withValidator()
 * hooks, mirroring tests/Unit/EventCatalog/VenueValidationRulesTest.php's
 * own structure. The currency_mismatch boundary check (a database read)
 * is deliberately not exercised here: it lives in
 * App\EventCatalog\Actions\CreateTicketType/UpdateTicketType, covered by
 * tests/Unit/EventCatalog/CreateTicketTypeTest.php/UpdateTicketTypeTest.php.
 */

function validCreateTicketTypePayload(array $overrides = []): array
{
    return [
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        ...$overrides,
    ];
}

it('accepts a well-formed create payload', function () {
    $validator = Validator::make(
        validCreateTicketTypePayload(),
        CreateTicketTypeData::rules(),
        [],
        [],
    );
    CreateTicketTypeData::withValidator($validator);

    expect($validator->fails())->toBeFalse();
});

it('rejects a negative price amount', function () {
    $validator = Validator::make(
        validCreateTicketTypePayload(['price' => ['amount' => -1, 'currency' => 'USD']]),
        CreateTicketTypeData::rules(),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('price.amount'))->toBeTrue();
});

it('accepts a zero price amount', function () {
    $validator = Validator::make(
        validCreateTicketTypePayload(['price' => ['amount' => 0, 'currency' => 'USD']]),
        CreateTicketTypeData::rules(),
        [],
        [],
    );
    CreateTicketTypeData::withValidator($validator);

    expect($validator->fails())->toBeFalse();
});

it('rejects a malformed currency code', function (string $currency) {
    $validator = Validator::make(
        validCreateTicketTypePayload(['price' => ['amount' => 5000, 'currency' => $currency]]),
        CreateTicketTypeData::rules(),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('price.currency'))->toBeTrue();
})->with([
    'lowercase' => ['usd'],
    'two letters' => ['US'],
    'four letters' => ['USDD'],
]);

it('rejects sales_end at or before sales_start', function () {
    $validator = Validator::make(
        validCreateTicketTypePayload([
            'sales_start' => '2026-08-01T00:00:00Z',
            'sales_end' => '2026-07-01T00:00:00Z',
        ]),
        CreateTicketTypeData::rules(),
        [],
        [],
    );
    CreateTicketTypeData::withValidator($validator);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('sales_end'))->toBeTrue();
});

it('accepts a sales window with only one side set', function (array $overrides) {
    $validator = Validator::make(
        validCreateTicketTypePayload($overrides),
        CreateTicketTypeData::rules(),
        [],
        [],
    );
    CreateTicketTypeData::withValidator($validator);

    expect($validator->fails())->toBeFalse();
})->with([
    'only sales_start' => [['sales_start' => '2026-08-01T00:00:00Z']],
    'only sales_end' => [['sales_end' => '2026-08-01T00:00:00Z']],
]);

it('rejects an update payload with only one side of the sales window', function (array $overrides) {
    $validator = Validator::make($overrides, UpdateTicketTypeData::rules(), [], []);
    UpdateTicketTypeData::withValidator($validator);

    expect($validator->fails())->toBeTrue();
})->with([
    'only sales_start' => [['sales_start' => '2026-08-01T00:00:00Z']],
    'only sales_end' => [['sales_end' => '2026-08-01T00:00:00Z']],
]);

it('accepts an update payload with the sales window given together', function () {
    $validator = Validator::make(
        ['sales_start' => '2026-08-01T00:00:00Z', 'sales_end' => '2026-09-01T00:00:00Z'],
        UpdateTicketTypeData::rules(),
        [],
        [],
    );
    UpdateTicketTypeData::withValidator($validator);

    expect($validator->fails())->toBeFalse();
});

it('accepts an update payload touching neither side of the sales window', function () {
    $validator = Validator::make(['name' => 'Renamed'], UpdateTicketTypeData::rules(), [], []);
    UpdateTicketTypeData::withValidator($validator);

    expect($validator->fails())->toBeFalse();
});

it('requires price.amount and price.currency together when price is given on update', function () {
    $validator = Validator::make(['price' => ['amount' => 5000]], UpdateTicketTypeData::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('price.currency'))->toBeTrue();
});
