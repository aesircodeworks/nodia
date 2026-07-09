<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\UpdateBranding;
use App\Tenancy\Data\TenantData;
use App\Tenancy\Data\UpdateTenantData;
use App\Tenancy\Exceptions\DefaultLocaleNotSupportedException;
use App\Tenancy\Models\Tenant;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create([
        'name' => 'Before',
        'default_locale' => 'en',
        'supported_locales' => ['en', 'pt'],
    ]));
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete(),
    );
});

it('rejects a new default locale absent from the existing supported locales', function () {
    $data = UpdateTenantData::from(['default_locale' => 'fr']);

    expect(fn () => app(TenantTransaction::class)->asPlatform(
        fn () => app(UpdateBranding::class)($this->tenant, $data),
    ))->toThrow(DefaultLocaleNotSupportedException::class);

    $fresh = app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->find($this->tenant->id));

    expect($fresh->default_locale)->toBe('en');
});

it('rejects shrinking the supported locales below the current default locale', function () {
    $data = UpdateTenantData::from(['supported_locales' => ['pt']]);

    expect(fn () => app(TenantTransaction::class)->asPlatform(
        fn () => app(UpdateBranding::class)($this->tenant, $data),
    ))->toThrow(DefaultLocaleNotSupportedException::class);

    $fresh = app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->find($this->tenant->id));

    expect($fresh->supported_locales)->toBe(['en', 'pt']);
});

it('accepts a combined locale change that keeps the invariant and returns TenantData', function () {
    $data = UpdateTenantData::from([
        'default_locale' => 'fr',
        'supported_locales' => ['fr', 'en'],
    ]);

    $result = app(TenantTransaction::class)->asPlatform(
        fn () => app(UpdateBranding::class)($this->tenant, $data),
    );

    expect($result)->toBeInstanceOf(TenantData::class)
        ->and($result->defaultLocale)->toBe('fr')
        ->and($result->supportedLocales)->toBe(['fr', 'en']);

    $fresh = app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->find($this->tenant->id));

    expect($fresh->default_locale)->toBe('fr')
        ->and($fresh->supported_locales)->toBe(['fr', 'en']);
});

it('updates the name and branding settings leaving locales untouched', function () {
    $data = UpdateTenantData::from([
        'name' => 'After',
        'branding_settings' => [
            'primary_color' => '#123456',
            'logo_url' => 'https://cdn.example/logo.svg',
        ],
    ]);

    $result = app(TenantTransaction::class)->asPlatform(
        fn () => app(UpdateBranding::class)($this->tenant, $data),
    );

    expect($result->name)->toBe('After')
        ->and($result->brandingSettings->primaryColor)->toBe('#123456')
        ->and($result->brandingSettings->logoUrl)->toBe('https://cdn.example/logo.svg');

    $fresh = app(TenantTransaction::class)->asPlatform(fn () => Tenant::query()->find($this->tenant->id));

    // jsonb does not preserve key order, so compare canonicalized.
    expect($fresh->name)->toBe('After')
        ->and($fresh->branding_settings)->toEqualCanonicalizing([
            'primary_color' => '#123456',
            'logo_url' => 'https://cdn.example/logo.svg',
        ])
        ->and($fresh->default_locale)->toBe('en')
        ->and($fresh->supported_locales)->toBe(['en', 'pt']);
});
