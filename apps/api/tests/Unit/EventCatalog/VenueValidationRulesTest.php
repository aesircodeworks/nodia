<?php

use App\EventCatalog\Data\CreateVenueData;
use App\EventCatalog\Support\Iso3166CountryCodes;
use Illuminate\Support\Facades\Validator;

/*
 * Stage-05a plan, task breakdown item 3: unit coverage for the country
 * (ISO 3166-1 alpha-2) and capacity (> 0, backing the venues_capacity_positive
 * CHECK) invariants CreateVenueData::rules() enforces, pure and DB-free
 * (Validator::make never touches the database).
 */

function validVenuePayload(array $overrides = []): array
{
    return [
        'name' => 'Grand Arena',
        'address' => '123 Main St',
        'city' => 'Austin',
        'country' => 'US',
        'capacity' => 5000,
        ...$overrides,
    ];
}

it('accepts every currently-assigned ISO 3166-1 alpha-2 code', function (string $code) {
    $validator = Validator::make(validVenuePayload(['country' => $code]), CreateVenueData::rules());

    expect($validator->fails())->toBeFalse();
})->with([
    'US' => ['US'], 'BR' => ['BR'], 'GB' => ['GB'], 'JP' => ['JP'], 'ZA' => ['ZA'],
]);

it('confirms the registry holds exactly the 249 currently-assigned codes', function () {
    expect(Iso3166CountryCodes::ALPHA2)->toHaveCount(249)
        ->and(array_unique(Iso3166CountryCodes::ALPHA2))->toHaveCount(249);
});

it('rejects a country code outside the ISO 3166-1 alpha-2 registry', function (string $code) {
    $validator = Validator::make(validVenuePayload(['country' => $code]), CreateVenueData::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('country'))->toBeTrue();
})->with([
    'three-letter code' => ['USA'],
    'lowercase' => ['us'],
    'unassigned code' => ['ZZ'],
    'empty string' => [''],
]);

it('accepts a positive capacity', function (int $capacity) {
    $validator = Validator::make(validVenuePayload(['capacity' => $capacity]), CreateVenueData::rules());

    expect($validator->fails())->toBeFalse();
})->with([1, 100, 20000]);

it('rejects a non-positive or non-integer capacity', function (mixed $capacity) {
    $validator = Validator::make(validVenuePayload(['capacity' => $capacity]), CreateVenueData::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('capacity'))->toBeTrue();
})->with([
    'zero' => [0],
    'negative' => [-1],
    'non-integer' => [1.5],
]);
