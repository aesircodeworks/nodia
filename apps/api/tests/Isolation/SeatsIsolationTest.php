<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\SeatFixture;
use Tests\Isolation\Support\SeatMapFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * seats is the standard Rls::applyTenantPolicies posture with no extra
 * policies (stage-05b plan Data model: seat mutation is tenant admin
 * surface, mirroring seat_maps, venues, and ticket_types), the same shape
 * those tables already established. Written first per the master plan's
 * TDD sequencing (stage-05b plan, TDD sequencing, Slice 1: "same matrix
 * for seats"), failing until the seats migration and its RLS policy land.
 */

beforeEach(fn () => SeatFixture::seed());

afterEach(fn () => SeatFixture::clean());

it('shows a tenant only its own seat row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('seats')->pluck('id'),
    );

    expect($ids->all())->toBe([SeatFixture::SEAT_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('seats')
            ->where('id', SeatFixture::SEAT_B)
            ->update(['section' => 'Hijacked']),
    );

    expect($affected)->toBe(0);

    $section = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('seats')->where('id', SeatFixture::SEAT_B)->value('section'),
    );

    expect($section)->not->toBe('Hijacked');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('seats')->where('id', SeatFixture::SEAT_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('seats')->where('id', SeatFixture::SEAT_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('seats')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'seat_map_id' => SeatMapFixture::SEAT_MAP_A,
            'section' => 'A',
            'row' => '2',
            'number' => '1',
            'position_x' => null,
            'position_y' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from seats'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(SeatFixture::SEAT_A);
});

it('lets nodia_platform read every tenant\'s seats without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('seats')->pluck('id'));

    expect($ids->all())->toContain(SeatFixture::SEAT_A, SeatFixture::SEAT_B);
});

it('rejects a nodia_platform write with no tenant asserted, seats has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('seats')->where('id', SeatFixture::SEAT_A)->update(['section' => 'Hijacked']),
    );

    expect($affected)->toBe(0);
});
