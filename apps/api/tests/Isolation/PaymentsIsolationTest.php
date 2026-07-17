<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\OrderFixture;
use Tests\Isolation\Support\PaymentFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * payments is the standard Rls::applyTenantPolicies posture with no
 * extra policies (stage-08a plan, Data model "payments"), mirroring
 * orders. Written first per the master plan's non-negotiable rule for
 * new tenant-scoped tables.
 */

/**
 * @return array<string, mixed>
 */
function validPaymentRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'order_id' => OrderFixture::ORDER_A,
        'gateway' => 'fake',
        'method' => 'card',
        'idempotency_key' => Str::uuid7()->toString(),
        'request_hash' => str_repeat('a', 64),
        'gateway_reference' => null,
        'amount' => 5000,
        'currency' => 'USD',
        'fee_amount' => 0,
        'commission_amount' => 0,
        'status' => 'initiated',
        'failure_code' => null,
        'expires_at' => null,
        'confirmed_at' => null,
        'failed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => PaymentFixture::seed());

afterEach(fn () => PaymentFixture::clean());

it('shows a tenant only its own payments', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('payments')->pluck('id'),
    );

    expect($ids->all())->toBe([PaymentFixture::PAYMENT_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('payments')->where('id', PaymentFixture::PAYMENT_B)->update(['status' => 'confirmed']),
    );

    expect($affected)->toBe(0);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('payments')->where('id', PaymentFixture::PAYMENT_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('payments')->where('id', PaymentFixture::PAYMENT_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('payments')->insert(validPaymentRow(['tenant_id' => TenantFixture::TENANT_B, 'order_id' => OrderFixture::ORDER_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('lets nodia_platform read every tenant\'s payments without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('payments')->pluck('id'));

    expect($ids->all())->toContain(PaymentFixture::PAYMENT_A, PaymentFixture::PAYMENT_B);
});

it('rejects a nodia_platform write with no tenant asserted, payments has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('payments')->where('id', PaymentFixture::PAYMENT_A)->update(['status' => 'confirmed']),
    );

    expect($affected)->toBe(0);
});
