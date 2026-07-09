<?php

use App\Support\Database\Rls;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * The tenants table is the aggregate root and carries no tenant_id; its
 * isolation policy compares id to app.tenant_id and is declared FOR SELECT,
 * so a tenant-scoped connection can read exactly its own row and nothing
 * else, and holds no write privilege at all, its own row included. Tenants
 * are created only through the platform role (stage-02 plan, Data model).
 */

beforeEach(fn () => TenantFixture::seed());

afterEach(fn () => TenantFixture::clean());

it('shows a tenant exactly its own row: the sentinel and other tenants are invisible', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tenants')->get(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->pluck('id')->all())->toBe([TenantFixture::TENANT_A]);
});

it('rejects a nodia_app insert even when the new row id matches the tenant context', function () {
    $id = Str::uuid7()->toString();

    expect(fn () => actingAsRole(Rls::APP_ROLE, $id, fn () => DB::table('tenants')->insert([
        'id' => $id,
        'name' => 'Self-registered',
        'branding_settings' => '{}',
        'default_locale' => 'en',
        'supported_locales' => '["en"]',
        'enabled_gateways' => '[]',
    ])))->toThrow(QueryException::class, 'row-level security');

    $exists = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('tenants')->where('id', $id)->exists(),
    );

    expect($exists)->toBeFalse();
});

it('makes a nodia_app update affect zero rows, its own row included', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tenants')->where('id', TenantFixture::TENANT_A)->update(['name' => 'tampered']),
    );

    expect($affected)->toBe(0);

    $name = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('tenants')->where('id', TenantFixture::TENANT_A)->value('name'),
    );

    expect($name)->toBe('Tenant A');
});

it('makes a nodia_app delete affect zero rows, its own row included', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('tenants')->where('id', TenantFixture::TENANT_A)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('tenants')->where('id', TenantFixture::TENANT_A)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('lets nodia_platform read every tenant row, the migrated sentinel included', function () {
    $ids = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('tenants')->pluck('id'),
    );

    expect($ids->all())->toContain(
        config()->string('tenancy.platform_tenant_id'),
        TenantFixture::TENANT_A,
        TenantFixture::TENANT_B,
    );
});

it('lets nodia_platform insert a tenant row', function () {
    $tenant = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => Tenant::factory()->create(['name' => 'Platform-created']),
    );

    $row = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('tenants')->where('id', $tenant->id)->first(),
    );

    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Platform-created');
});
