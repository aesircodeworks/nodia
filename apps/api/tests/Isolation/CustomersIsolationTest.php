<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\CustomerFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * customers is the standard Rls::applyTenantPolicies posture with no
 * extra policies (stage-03 plan Data model: "standard single-table
 * policy"), the same shape as memberships minus memberships_self_read.
 * Task breakdown item 12's own wording: "a customer row created under
 * tenant A is invisible and immutable under tenant B."
 */

beforeEach(fn () => CustomerFixture::seed());

afterEach(fn () => CustomerFixture::clean());

it('shows a tenant only its own customer row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('customers')->pluck('id'),
    );

    expect($ids->all())->toBe([CustomerFixture::CUSTOMER_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('customers')
            ->where('id', CustomerFixture::CUSTOMER_B)
            ->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);

    $name = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('customers')->where('id', CustomerFixture::CUSTOMER_B)->value('name'),
    );

    expect($name)->not->toBe('Hijacked');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('customers')->where('id', CustomerFixture::CUSTOMER_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('customers')->where('id', CustomerFixture::CUSTOMER_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('customers')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'email' => 'forged@customer-fixture.example',
            'name' => 'Forged',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from customers'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(CustomerFixture::CUSTOMER_A);
});

it('lets nodia_platform read every tenant customers without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('customers')->pluck('id'));

    expect($ids->all())->toContain(CustomerFixture::CUSTOMER_A, CustomerFixture::CUSTOMER_B);
});

it('rejects a nodia_platform write with no tenant asserted, customers has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('customers')->where('id', CustomerFixture::CUSTOMER_A)->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);
});

it('permits the same email under two different tenants, unique per tenant not globally', function () {
    $tenantIds = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('customers')->where('email', CustomerFixture::SHARED_EMAIL)->pluck('tenant_id')->sort()->values(),
    );

    expect($tenantIds->all())->toEqualCanonicalizing([TenantFixture::TENANT_A, TenantFixture::TENANT_B]);
});

it('rejects a duplicate email within the same tenant through the unique constraint', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('customers')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_A,
            'email' => CustomerFixture::SHARED_EMAIL,
            'name' => 'Duplicate',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'unique');
});
