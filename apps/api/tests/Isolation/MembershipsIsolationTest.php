<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\IdentityFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * memberships is the standard Rls::applyTenantPolicies posture (no
 * platform write policy: membership mutation is tenant admin surface, not
 * platform-admin surface) plus one extra permissive SELECT policy,
 * memberships_self_read, matching app.user_id independent of any tenant
 * context (stage-03 plan, Data model). The two-tenant fixture proves the
 * full deny matrix, and a dedicated block proves self-read returns
 * exactly the caller's own rows across tenants and no one else's (Slice 3
 * risk item).
 */

beforeEach(fn () => IdentityFixture::seed());

afterEach(fn () => IdentityFixture::clean());

it('shows a tenant only its own membership rows', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('memberships')->pluck('id'),
    );

    expect($ids->all())->toBe([IdentityFixture::MEMBERSHIP_A_IN_TENANT_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('memberships')
            ->where('id', IdentityFixture::MEMBERSHIP_B_IN_TENANT_B)
            ->update(['role_id' => IdentityFixture::ROLE_A]),
    );

    expect($affected)->toBe(0);

    $roleId = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('memberships')->where('id', IdentityFixture::MEMBERSHIP_B_IN_TENANT_B)->value('role_id'),
    );

    expect($roleId)->toBe(IdentityFixture::ROLE_B);
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('memberships')->where('id', IdentityFixture::MEMBERSHIP_B_IN_TENANT_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('memberships')->where('id', IdentityFixture::MEMBERSHIP_B_IN_TENANT_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('memberships')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => IdentityFixture::USER_B,
            'tenant_id' => TenantFixture::TENANT_B,
            'role_id' => IdentityFixture::ROLE_B,
            'scope' => 'tenant',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from memberships'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(IdentityFixture::MEMBERSHIP_A_IN_TENANT_A);
});

it('lets nodia_platform read every tenant memberships without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('memberships')->pluck('id'));

    expect($ids->all())->toContain(
        IdentityFixture::MEMBERSHIP_A_IN_TENANT_A,
        IdentityFixture::MEMBERSHIP_A_IN_TENANT_B,
        IdentityFixture::MEMBERSHIP_B_IN_TENANT_B,
    );
});

it('rejects a nodia_platform write with no tenant asserted, memberships has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('memberships')->where('id', IdentityFixture::MEMBERSHIP_A_IN_TENANT_A)->update(['role_id' => IdentityFixture::ROLE_B]),
    );

    expect($affected)->toBe(0);
});

it('returns exactly the callers own rows through memberships_self_read, across tenants and without a tenant context', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        null,
        fn () => DB::table('memberships')->pluck('id'),
        userId: IdentityFixture::USER_A,
    );

    expect($rows->all())->toEqualCanonicalizing([
        IdentityFixture::MEMBERSHIP_A_IN_TENANT_A,
        IdentityFixture::MEMBERSHIP_A_IN_TENANT_B,
    ]);
});

it('excludes another users rows from memberships_self_read', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        null,
        fn () => DB::table('memberships')->pluck('id'),
        userId: IdentityFixture::USER_B,
    );

    expect($rows->all())->toBe([IdentityFixture::MEMBERSHIP_B_IN_TENANT_B]);
});

it('widens visibility with a tenant context on top of self-read, never narrows it', function () {
    // Two permissive policies OR together: the tenant_isolation match
    // (every tenant B row) plus every self-read row for USER_A, so tenant
    // B's own membership (owned by USER_B) is visible here through the
    // tenant policy alone, not because self-read admits it.
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('memberships')->pluck('id'),
        userId: IdentityFixture::USER_A,
    );

    expect($rows->all())->toEqualCanonicalizing([
        IdentityFixture::MEMBERSHIP_A_IN_TENANT_A,
        IdentityFixture::MEMBERSHIP_A_IN_TENANT_B,
        IdentityFixture::MEMBERSHIP_B_IN_TENANT_B,
    ]);
});
