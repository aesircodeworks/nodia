<?php

use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05a plan, task breakdown item 6: the schema half of the additive
 * settlement currency column, mirroring
 * tests/Unit/Identity/MfaSchemaTest.php's own precedent for proving an
 * additive migration directly against information_schema rather than only
 * through the Action that reads it (ResolveTenantSettlementCurrencyTest.php
 * covers that side). Stage 2 created tenants without a settlement currency
 * column (verified before writing this test: no such column exists in
 * database/migrations/2026_07_09_000001_create_tenants_table.php), so this
 * assertion fails until the new migration lands.
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

it('adds a non-null settlement_currency column to tenants with a sane default', function (): void {
    $column = DB::selectOne(
        "select data_type, is_nullable, column_default
            from information_schema.columns
            where table_name = 'tenants' and column_name = 'settlement_currency'",
    );

    expect($column)->not->toBeNull()
        ->and($column->data_type)->toBe('character varying')
        ->and($column->is_nullable)->toBe('NO')
        ->and($column->column_default)->toContain('USD');
});

it('backfills the sentinel platform tenant created by the original tenants migration', function (): void {
    $currency = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->findOrFail(config()->string('tenancy.platform_tenant_id'))->settlement_currency,
    );

    expect($currency)->toBe('USD');
});

it('defaults a newly created tenant to the same settlement currency', function (): void {
    $tenant = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create(),
    );

    expect($tenant->settlement_currency)->toBe('USD');
});
