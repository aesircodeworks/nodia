<?php

use App\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

it('generates uuid version 7 primary keys', function () {
    $tenant = new Tenant;

    $id = $tenant->newUniqueId();

    expect($tenant->usesUniqueIds())->toBeTrue()
        ->and(Str::isUuid($id))->toBeTrue()
        ->and($id[14])->toBe('7');
});

it('casts the jsonb configuration columns to arrays', function () {
    $tenant = Tenant::factory()->make();

    expect($tenant->branding_settings)->toBeArray()
        ->and($tenant->supported_locales)->toBeArray()
        ->and($tenant->enabled_gateways)->toBeArray();
});

it('builds factory tenants whose default locale is one of the supported locales', function () {
    $tenant = Tenant::factory()->make();

    expect($tenant->supported_locales)->toContain($tenant->default_locale);
});
