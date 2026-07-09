<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\CreateTenant;
use App\Tenancy\Data\CreateTenantData;
use App\Tenancy\Data\TenantData;
use App\Tenancy\Exceptions\DefaultLocaleNotSupportedException;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete(),
    );
});

it('rejects a default locale absent from the supported locales', function () {
    $data = CreateTenantData::from([
        'name' => 'Acme Tickets',
        'default_locale' => 'fr',
        'supported_locales' => ['en', 'pt'],
    ]);

    expect(fn () => app(TenantTransaction::class)->asPlatform(fn () => app(CreateTenant::class)($data)))
        ->toThrow(DefaultLocaleNotSupportedException::class);

    $created = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->count(),
    );

    expect($created)->toBe(0);
});

it('creates a tenant and returns its TenantData', function () {
    $data = CreateTenantData::from([
        'name' => 'Acme Tickets',
        'default_locale' => 'pt',
        'supported_locales' => ['pt', 'en'],
        'branding_settings' => ['primary_color' => '#1a2b3c'],
        'enabled_gateways' => ['fake_gateway'],
    ]);

    $result = app(TenantTransaction::class)->asPlatform(fn () => app(CreateTenant::class)($data));

    expect($result)->toBeInstanceOf(TenantData::class)
        ->and(Str::isUuid($result->id))->toBeTrue()
        ->and($result->name)->toBe('Acme Tickets')
        ->and($result->defaultLocale)->toBe('pt')
        ->and($result->supportedLocales)->toBe(['pt', 'en'])
        ->and($result->brandingSettings->primaryColor)->toBe('#1a2b3c')
        ->and($result->enabledGateways)->toBe(['fake_gateway'])
        ->and($result->payoutSchedule)->toBeNull();

    $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->find($result->id));

    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('Acme Tickets')
        ->and($tenant->branding_settings)->toEqualCanonicalizing(['primary_color' => '#1a2b3c', 'logo_url' => null])
        ->and($tenant->enabled_gateways)->toBe(['fake_gateway']);
});

it('defaults branding, gateways, and payout schedule when absent from the request', function () {
    $data = CreateTenantData::from([
        'name' => 'Acme Tickets',
        'default_locale' => 'en',
        'supported_locales' => ['en'],
    ]);

    $result = app(TenantTransaction::class)->asPlatform(fn () => app(CreateTenant::class)($data));

    $tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->find($result->id));

    expect($tenant->branding_settings)->toBe([])
        ->and($tenant->enabled_gateways)->toBe([])
        ->and($tenant->payout_schedule)->toBeNull()
        ->and($result->enabledGateways)->toBe([])
        ->and($result->payoutSchedule)->toBeNull();
});

it('serializes TenantData with snake_case keys and ISO 8601 UTC timestamps', function () {
    $data = CreateTenantData::from([
        'name' => 'Acme Tickets',
        'default_locale' => 'en',
        'supported_locales' => ['en'],
    ]);

    $result = app(TenantTransaction::class)->asPlatform(fn () => app(CreateTenant::class)($data));

    $wire = $result->toArray();

    expect(array_keys($wire))->toBe([
        'id',
        'name',
        'branding_settings',
        'default_locale',
        'supported_locales',
        'enabled_gateways',
        'payout_schedule',
        'created_at',
        'updated_at',
    ])
        // Both keys are always present so empty branding still serializes
        // as a JSON object, never as [].
        ->and($wire['branding_settings'])->toBe(['primary_color' => null, 'logo_url' => null])
        ->and($wire['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($wire['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});
