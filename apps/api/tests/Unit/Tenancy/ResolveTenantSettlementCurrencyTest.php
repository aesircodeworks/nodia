<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Actions\ResolveTenantSettlementCurrency;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05a plan, task breakdown item 6: the read Action EventCatalog's
 * ticket type currency validation (task breakdown item 8, slice 3) will
 * call to check a ticket type's currency against the tenant's settlement
 * currency, never by touching Tenancy tables directly (section 3.1
 * boundary rule enforced by tests/Architecture/ContextBoundariesTest),
 * mirroring ResolveTenantDefaultLocaleTest.php's own precedent for the
 * identical crossing.
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

it('resolves a tenant own settlement currency', function (): void {
    $tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(['settlement_currency' => 'EUR'])->id,
    );

    $currency = app(TenantTransaction::class)->asPlatform(
        fn () => app(ResolveTenantSettlementCurrency::class)($tenantId),
    );

    expect($currency)->toBe('EUR');
});

it('throws for an unknown tenant id', function (): void {
    $invoke = fn () => app(TenantTransaction::class)->asPlatform(
        fn () => app(ResolveTenantSettlementCurrency::class)((string) Str::uuid7()),
    );

    expect($invoke)->toThrow(ModelNotFoundException::class);
});
