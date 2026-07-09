<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * nodia_resolver is the narrow infrastructure role that answers anonymous
 * domain resolution (storefront Host lookup, Caddy domain verification)
 * without assuming the audited cross-tenant platform role of
 * system-design 4.3. Its entire surface is SELECT on tenant_domains: it
 * reads every tenant's domains with no tenant context, and nothing else.
 */

beforeEach(fn () => TenantFixture::seed());

afterEach(fn () => TenantFixture::clean());

it('reads every tenant\'s domains with no tenant context set', function () {
    $domains = actingAsRole(
        Rls::RESOLVER_ROLE,
        null,
        fn () => DB::table('tenant_domains')->orderBy('domain')->pluck('domain')->all(),
    );

    expect($domains)->toBe([TenantFixture::DOMAIN_A, TenantFixture::DOMAIN_B]);
});

it('cannot insert into tenant_domains', function () {
    actingAsRole(Rls::RESOLVER_ROLE, null, fn () => DB::table('tenant_domains')->insert([
        'id' => '019797f0-0000-7000-8000-0000000000ff',
        'tenant_id' => TenantFixture::TENANT_A,
        'domain' => 'smuggled.example',
        'is_primary' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
})->throws(QueryException::class, 'permission denied');

it('cannot update tenant_domains', function () {
    actingAsRole(
        Rls::RESOLVER_ROLE,
        null,
        fn () => DB::table('tenant_domains')
            ->where('domain', TenantFixture::DOMAIN_A)
            ->update(['domain' => 'hijacked.example']),
    );
})->throws(QueryException::class, 'permission denied');

it('cannot delete from tenant_domains', function () {
    actingAsRole(
        Rls::RESOLVER_ROLE,
        null,
        fn () => DB::table('tenant_domains')->where('domain', TenantFixture::DOMAIN_A)->delete(),
    );
})->throws(QueryException::class, 'permission denied');

it('cannot read the tenants table', function () {
    actingAsRole(Rls::RESOLVER_ROLE, null, fn () => DB::table('tenants')->get());
})->throws(QueryException::class, 'permission denied');

it('grants no cross-tenant visibility to the tenant-scoped role', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tenant_domains')->pluck('domain')->all(),
    );

    expect($rows)->toBe([TenantFixture::DOMAIN_A]);
});
