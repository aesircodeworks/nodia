<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\OrderFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * orders is the standard Rls::applyTenantPolicies posture with no extra
 * policies (stage-07 plan, Data model "orders"), mirroring holds.
 * Written first per the master plan's TDD sequencing (stage-07 plan,
 * task breakdown item 2).
 */

/**
 * @return array<string, mixed>
 */
function validOrderRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'customer_id' => OrderFixture::CUSTOMER_A,
        'event_id' => EventFixture::EVENT_A,
        'hold_id' => Str::uuid7()->toString(),
        'status' => 'pending',
        'subtotal_amount' => 5000,
        'discount_amount' => 0,
        'fees_amount' => 0,
        'total_amount' => 5000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => OrderFixture::seed());

afterEach(fn () => OrderFixture::clean());

it('shows a tenant only its own order', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('orders')->pluck('id'),
    );

    expect($ids->all())->toBe([OrderFixture::ORDER_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('orders')->where('id', OrderFixture::ORDER_B)->update(['status' => 'canceled']),
    );

    expect($affected)->toBe(0);

    $status = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('orders')->where('id', OrderFixture::ORDER_B)->value('status'),
    );

    expect($status)->toBe('pending');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('orders')->where('id', OrderFixture::ORDER_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('orders')->where('id', OrderFixture::ORDER_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('orders')->insert(validOrderRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from orders'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(OrderFixture::ORDER_A);
});

it('lets nodia_platform read every tenant\'s orders without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('orders')->pluck('id'));

    expect($ids->all())->toContain(OrderFixture::ORDER_A, OrderFixture::ORDER_B);
});

it('rejects a nodia_platform write with no tenant asserted, orders has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('orders')->where('id', OrderFixture::ORDER_A)->update(['status' => 'canceled']),
    );

    expect($affected)->toBe(0);
});
