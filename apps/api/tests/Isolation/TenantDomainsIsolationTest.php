<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * tenant_domains is the first tenant-scoped child table under the standard
 * Rls helper policies. The reusable two-tenant fixture proves the full
 * deny matrix here: cross-tenant SELECT empty, UPDATE and DELETE zero
 * rows, INSERT with a foreign tenant_id rejected by WITH CHECK, and a raw
 * SQL query that bypasses all Eloquent scoping still isolated, because
 * the database is the guarantee (ADR 003).
 */

beforeEach(fn () => TenantFixture::seed());

afterEach(fn () => TenantFixture::clean());

it('shows a tenant only its own domains', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tenant_domains')->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->tenant_id)->toBe(TenantFixture::TENANT_A)
        ->and($rows->first()->domain)->toBe(TenantFixture::DOMAIN_A);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tenant_domains')
            ->where('domain', TenantFixture::DOMAIN_B)
            ->update(['domain' => 'hijacked.example']),
    );

    expect($affected)->toBe(0);

    $domain = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('tenant_domains')->where('tenant_id', TenantFixture::TENANT_B)->value('domain'),
    );

    expect($domain)->toBe(TenantFixture::DOMAIN_B);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tenant_domains')
            ->where('domain', TenantFixture::DOMAIN_B)
            ->delete(),
    );

    expect($affected)->toBe(0);

    $count = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('tenant_domains')->count(),
    );

    expect($count)->toBe(1);
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tenant_domains')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'domain' => 'forged.example',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');

    $count = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('tenant_domains')->count(),
    );

    expect($count)->toBe(1);
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from tenant_domains'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->tenant_id)->toBe(TenantFixture::TENANT_A)
        ->and($rows[0]->domain)->toBe(TenantFixture::DOMAIN_A);
});

it('lets nodia_platform read both tenants domains without any tenant context', function () {
    $domains = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('tenant_domains')->pluck('domain'),
    );

    expect($domains->all())->toContain(TenantFixture::DOMAIN_A, TenantFixture::DOMAIN_B);
});
