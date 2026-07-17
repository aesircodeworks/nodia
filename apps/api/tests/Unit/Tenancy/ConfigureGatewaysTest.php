<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\ConfigureGateways;
use App\Tenancy\Data\TenantData;
use App\Tenancy\Exceptions\InvalidGatewayConfigurationException;
use App\Tenancy\Models\Tenant;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create([
        'enabled_gateways' => ['pre_existing'],
    ]));
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete(),
    );
});

it('accepts any list of non-empty strings without adapter capability validation', function () {
    $result = app(TenantTransaction::class)->asPlatform(
        fn () => app(ConfigureGateways::class)($this->tenant, ['fake_gateway', 'not_a_real_adapter_yet']),
    );

    expect($result)->toBeInstanceOf(TenantData::class)
        ->and($result->enabledGateways)->toBe(['fake_gateway', 'not_a_real_adapter_yet']);

    $fresh = app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->find($this->tenant->id));

    expect($fresh->enabled_gateways)->toBe(['fake_gateway', 'not_a_real_adapter_yet']);
});

it('accepts an empty list, clearing the enabled gateways', function () {
    $result = app(TenantTransaction::class)->asPlatform(
        fn () => app(ConfigureGateways::class)($this->tenant, []),
    );

    expect($result->enabledGateways)->toBe([]);

    $fresh = app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->find($this->tenant->id));

    expect($fresh->enabled_gateways)->toBe([]);
});

it('rejects anything that is not a list of non-empty strings', function (array $gateways) {
    expect(fn () => app(TenantTransaction::class)->asPlatform(
        fn () => app(ConfigureGateways::class)($this->tenant, $gateways),
    ))->toThrow(InvalidGatewayConfigurationException::class);

    $fresh = app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->find($this->tenant->id));

    expect($fresh->enabled_gateways)->toBe(['pre_existing']);
})->with([
    'empty string element' => [['fake_gateway', '']],
    'integer element' => [[123]],
    'null element' => [[null]],
    'nested array element' => [[['fake_gateway']]],
    'associative array' => [['primary' => 'fake_gateway']],
]);
