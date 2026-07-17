<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\SubmerchantAccountFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * submerchant_accounts is the standard Rls::applyTenantPolicies posture
 * with no extra policies (stage-08c plan, Data model
 * "submerchant_accounts"), mirroring payments and refunds. Written
 * first per the master plan's non-negotiable rule for new tenant-scoped
 * tables.
 */

beforeEach(fn () => SubmerchantAccountFixture::seed());

afterEach(fn () => SubmerchantAccountFixture::clean());

it('shows a tenant only its own submerchant accounts', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('submerchant_accounts')->pluck('id'),
    );

    expect($ids->all())->toBe([SubmerchantAccountFixture::ACCOUNT_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('submerchant_accounts')->where('id', SubmerchantAccountFixture::ACCOUNT_B)->update(['status' => 'active']),
    );

    expect($affected)->toBe(0);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('submerchant_accounts')->where('id', SubmerchantAccountFixture::ACCOUNT_B)->delete(),
    );

    expect($affected)->toBe(0);
});

it('rejects an insert claiming another tenant id', function () {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
        DB::table('submerchant_accounts')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'gateway' => 'fake',
            'status' => 'pending',
            'requirements' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
})->throws(QueryException::class, 'row-level security');

it('grants the platform role read across tenants', function () {
    $count = actingAsRole(
        Rls::PLATFORM_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('submerchant_accounts')->count(),
    );

    expect($count)->toBe(2);
});
