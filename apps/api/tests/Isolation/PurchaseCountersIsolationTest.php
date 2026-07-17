<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\PurchaseCounterFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\TicketTypeFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * purchase_counters is the standard Rls::applyTenantPolicies posture with
 * no extra policies (stage-10 plan, Data model "purchase_counters":
 * "tenant-scoped ... The RLS policy ... ships in the same migration, or
 * the isolation suite blocks the merge"), mirroring
 * ticket_type_inventory's own shape. Written first per the master plan's
 * TDD sequencing (stage-10 plan, TDD sequencing Slice 3: "Isolation
 * (first): purchase_counters probes under the two-tenant fixture, cross-
 * tenant SELECT and UPDATE affect zero rows"), failing until the
 * purchase_counters migration and its RLS policy land. Also proves the
 * quantity non-negative CHECK and the unique(customer_id, ticket_type_id)
 * conflict target the guarded upsert relies on.
 */

/**
 * @return array<string, mixed>
 */
function validPurchaseCounterRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'customer_id' => PurchaseCounterFixture::CUSTOMER_A,
        'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A,
        'quantity' => 0,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => PurchaseCounterFixture::seed());

afterEach(fn () => PurchaseCounterFixture::clean());

it('shows a tenant only its own purchase counter', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('purchase_counters')->pluck('id'),
    );

    expect($ids->all())->toBe([PurchaseCounterFixture::COUNTER_A]);
});

it('makes a cross-tenant select affect zero rows', function () {
    $row = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('purchase_counters')->where('id', PurchaseCounterFixture::COUNTER_B)->first(),
    );

    expect($row)->toBeNull();
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('purchase_counters')->where('id', PurchaseCounterFixture::COUNTER_B)->update(['quantity' => 99]),
    );

    expect($affected)->toBe(0);

    $quantity = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('purchase_counters')->where('id', PurchaseCounterFixture::COUNTER_B)->value('quantity'),
    );

    expect($quantity)->not->toBe(99);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('purchase_counters')->where('id', PurchaseCounterFixture::COUNTER_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('purchase_counters')->where('id', PurchaseCounterFixture::COUNTER_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('purchase_counters')->insert(validPurchaseCounterRow([
            'tenant_id' => TenantFixture::TENANT_B,
            'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_B,
        ])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('ships the quantity non-negative CHECK constraint', function () {
    $constraint = DB::selectOne(
        "select conname from pg_constraint where conname = 'purchase_counters_quantity_non_negative'",
    );

    expect($constraint)->not->toBeNull();
});

it('rejects an insert violating the quantity non-negative CHECK regardless of tenant context', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('purchase_counters')->insert(validPurchaseCounterRow(['quantity' => -1])),
    ))->toThrow(QueryException::class, 'purchase_counters_quantity_non_negative');
});

it('rejects a second row for the same customer and ticket type through the unique constraint', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('purchase_counters')->insert(validPurchaseCounterRow()),
    ))->toThrow(QueryException::class);
});

it('lets nodia_platform read every tenant\'s purchase counters without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('purchase_counters')->pluck('id'));

    expect($ids->all())->toContain(PurchaseCounterFixture::COUNTER_A, PurchaseCounterFixture::COUNTER_B);
});

it('rejects a nodia_platform write with no tenant asserted, purchase_counters has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('purchase_counters')->where('id', PurchaseCounterFixture::COUNTER_A)->update(['quantity' => 99]),
    );

    expect($affected)->toBe(0);
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from purchase_counters'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(PurchaseCounterFixture::COUNTER_A);
});
