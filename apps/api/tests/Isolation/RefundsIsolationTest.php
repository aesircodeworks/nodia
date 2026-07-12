<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\PaymentFixture;
use Tests\Isolation\Support\RefundFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * refunds is the standard Rls::applyTenantPolicies posture with no
 * extra policies (stage-08b plan, Data model "refunds"), mirroring
 * payments. Written first per the master plan's non-negotiable rule for
 * new tenant-scoped tables.
 */

beforeEach(fn () => RefundFixture::seed());

afterEach(fn () => RefundFixture::clean());

it('shows a tenant only its own refunds', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('refunds')->pluck('id'),
    );

    expect($ids->all())->toBe([RefundFixture::REFUND_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('refunds')->where('id', RefundFixture::REFUND_B)->update(['status' => 'processing']),
    );

    expect($affected)->toBe(0);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('refunds')->where('id', RefundFixture::REFUND_B)->delete(),
    );

    expect($affected)->toBe(0);
});

it('rejects an insert claiming another tenant id', function () {
    actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
        DB::table('refunds')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'payment_id' => PaymentFixture::PAYMENT_B,
            'amount' => 100,
            'currency' => 'USD',
            'status' => 'pending',
            'commission_amount' => 0,
            'idempotency_key' => Str::uuid7()->toString(),
            'request_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
})->throws(QueryException::class, 'row-level security');

it('grants the platform role read across tenants', function () {
    $count = actingAsRole(
        Rls::PLATFORM_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('refunds')->count(),
    );

    expect($count)->toBe(2);
});
