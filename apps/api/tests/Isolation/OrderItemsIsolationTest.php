<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\OrderFixture;
use Tests\Isolation\Support\OrderItemFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * order_items is the standard Rls::applyTenantPolicies posture with no
 * extra policies, tenant_id denormalized even though derivable through
 * order_id, mirroring hold_items (stage-07 plan, Data model
 * "order_items"; task breakdown item 2).
 */

/**
 * @return array<string, mixed>
 */
function validOrderItemRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'order_id' => OrderFixture::ORDER_A,
        'ticket_type_id' => OrderItemFixture::TICKET_TYPE_A,
        'quantity' => 1,
        'unit_price_amount' => 5000,
        'currency' => 'USD',
        'attendee_names' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => OrderItemFixture::seed());

afterEach(fn () => OrderItemFixture::clean());

it('shows a tenant only its own order items', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('order_items')->pluck('id'),
    );

    expect($ids->all())->toBe([OrderItemFixture::ITEM_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('order_items')->where('id', OrderItemFixture::ITEM_B)->update(['quantity' => 9]),
    );

    expect($affected)->toBe(0);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('order_items')->where('id', OrderItemFixture::ITEM_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('order_items')->where('id', OrderItemFixture::ITEM_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('order_items')->insert(validOrderItemRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('lets nodia_platform read every tenant\'s order items without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('order_items')->pluck('id'));

    expect($ids->all())->toContain(OrderItemFixture::ITEM_A, OrderItemFixture::ITEM_B);
});

it('rejects a nodia_platform write with no tenant asserted, order_items has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('order_items')->where('id', OrderItemFixture::ITEM_A)->update(['quantity' => 9]),
    );

    expect($affected)->toBe(0);
});
