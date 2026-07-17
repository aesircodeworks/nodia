<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\ResolveTenantDefaultLocale;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 13: the minimal cross-context read
 * App\Identity\Actions\RegisterCustomer uses for its own locale fallback
 * (system-design 12).
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

it('resolves a tenant own default locale', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['default_locale' => 'pt-BR'])->id,
    );

    $locale = app(TenantTransaction::class)->asPlatform(
        fn () => app(ResolveTenantDefaultLocale::class)($tenantId),
    );

    expect($locale)->toBe('pt-BR');
});

it('throws for an unknown tenant id', function (): void {
    $invoke = fn () => app(TenantTransaction::class)->asPlatform(
        fn () => app(ResolveTenantDefaultLocale::class)((string) Str::uuid7()),
    );

    expect($invoke)->toThrow(ModelNotFoundException::class);
});
