<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\ResolveTenantLocaleSettings;
use App\Tenancy\Data\TenantLocaleSettingsData;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05a plan, task breakdown item 5: the cross-context read
 * App\EventCatalog\Data\Concerns\ValidatesEventInvariants uses to check a
 * translatable payload against the tenant's default_locale and
 * supported_locales together, mirroring
 * tests/Unit/Tenancy/ResolveTenantDefaultLocaleTest.php's own precedent.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete(),
    );
});

it('resolves a tenant own default locale and supported locales together', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['default_locale' => 'en', 'supported_locales' => ['en', 'fr']])->id,
    );

    $settings = app(TenantTransaction::class)->asPlatform(
        fn () => app(ResolveTenantLocaleSettings::class)($tenantId),
    );

    expect($settings)->toBeInstanceOf(TenantLocaleSettingsData::class)
        ->and($settings->defaultLocale)->toBe('en')
        ->and($settings->supportedLocales)->toBe(['en', 'fr']);
});

it('throws for an unknown tenant id', function (): void {
    $invoke = fn () => app(TenantTransaction::class)->asPlatform(
        fn () => app(ResolveTenantLocaleSettings::class)((string) Str::uuid7()),
    );

    expect($invoke)->toThrow(ModelNotFoundException::class);
});
