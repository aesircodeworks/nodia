<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\EventFixture;
use Tests\Isolation\Support\HoldFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * holds is the standard Rls::applyTenantPolicies posture with no extra
 * policies (stage-06 plan, Data model "holds"), mirroring ticket_types.
 * Written first per the master plan's TDD sequencing (stage-06 plan,
 * task breakdown item 1's slice 0 probe, merged with item 4).
 */

/**
 * @return array<string, mixed>
 */
function validHoldRow(array $overrides = []): array
{
    return [
        'id' => Str::uuid7()->toString(),
        'tenant_id' => TenantFixture::TENANT_A,
        'event_id' => EventFixture::EVENT_A,
        'customer_id' => null,
        'status' => 'active',
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => HoldFixture::seed());

afterEach(fn () => HoldFixture::clean());

it('shows a tenant only its own hold', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('holds')->pluck('id'),
    );

    expect($ids->all())->toBe([HoldFixture::HOLD_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('holds')->where('id', HoldFixture::HOLD_B)->update(['status' => 'released']),
    );

    expect($affected)->toBe(0);

    $status = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('holds')->where('id', HoldFixture::HOLD_B)->value('status'),
    );

    expect($status)->toBe('active');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('holds')->where('id', HoldFixture::HOLD_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('holds')->where('id', HoldFixture::HOLD_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('holds')->insert(validHoldRow(['tenant_id' => TenantFixture::TENANT_B])),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from holds'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(HoldFixture::HOLD_A);
});

it('lets nodia_platform read every tenant\'s holds without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('holds')->pluck('id'));

    expect($ids->all())->toContain(HoldFixture::HOLD_A, HoldFixture::HOLD_B);
});

it('rejects a nodia_platform write with no tenant asserted, holds has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('holds')->where('id', HoldFixture::HOLD_A)->update(['status' => 'released']),
    );

    expect($affected)->toBe(0);
});
