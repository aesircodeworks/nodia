<?php

use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Str;

it('generates uuid version 7 primary keys', function () {
    $domain = new TenantDomain;

    $id = $domain->newUniqueId();

    expect($domain->usesUniqueIds())->toBeTrue()
        ->and(Str::isUuid($id))->toBeTrue()
        ->and($id[14])->toBe('7');
});

it('casts is_primary to boolean and defaults it off in the factory', function () {
    $domain = TenantDomain::factory()->make(['is_primary' => 1]);

    expect($domain->is_primary)->toBeTrue()
        ->and(TenantDomain::factory()->make()->is_primary)->toBeFalse();
});

it('builds factory domains already lowercase', function () {
    $domain = TenantDomain::factory()->make();

    expect($domain->domain)->toBe(strtolower($domain->domain));
});
