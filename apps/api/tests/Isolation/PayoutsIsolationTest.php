<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\PayoutFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * payouts is the standard Rls::applyTenantPolicies posture with no extra
 * policies (stage-08c plan, Data model "payouts"), mirroring
 * submerchant_accounts. Written first per the master plan's non-negotiable
 * rule for new tenant-scoped tables.
 */

beforeEach(fn () => PayoutFixture::seed());

afterEach(fn () => PayoutFixture::clean());

it('shows a tenant only its own payouts', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('payouts')->pluck('id'),
    );

    expect($ids->all())->toBe([PayoutFixture::PAYOUT_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('payouts')->where('id', PayoutFixture::PAYOUT_B)->update(['status' => 'paid']),
    );

    expect($affected)->toBe(0);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('payouts')->where('id', PayoutFixture::PAYOUT_B)->delete(),
    );

    expect($affected)->toBe(0);
});

it('rejects an insert claiming another tenant id', function () {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
        DB::table('payouts')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'gateway' => 'fake',
            'gateway_reference' => Str::uuid7()->toString(),
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
})->throws(QueryException::class, 'row-level security');

it('grants the platform role read across tenants', function () {
    $count = actingAsRole(
        Rls::PLATFORM_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('payouts')->count(),
    );

    expect($count)->toBe(2);
});
