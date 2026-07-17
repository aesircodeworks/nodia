<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\SeatMapFixture;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\VenueFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * seat_maps is the standard Rls::applyTenantPolicies posture with no
 * extra policies (stage-05b plan Data model: seat map mutation is tenant
 * admin surface, mirroring venues, events, and ticket_types), the same
 * shape those tables already established. Written first per the master
 * plan's TDD sequencing (stage-05b plan, TDD sequencing, Slice 1:
 * "two-tenant fixture proves tenant B cannot select, update, or delete
 * tenant A's seat_maps rows"), failing until the seat_maps migration and
 * its RLS policy land.
 */

beforeEach(fn () => SeatMapFixture::seed());

afterEach(fn () => SeatMapFixture::clean());

it('shows a tenant only its own seat map row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('seat_maps')->pluck('id'),
    );

    expect($ids->all())->toBe([SeatMapFixture::SEAT_MAP_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('seat_maps')
            ->where('id', SeatMapFixture::SEAT_MAP_B)
            ->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);

    $name = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('seat_maps')->where('id', SeatMapFixture::SEAT_MAP_B)->value('name'),
    );

    expect($name)->not->toBe('Hijacked');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('seat_maps')->where('id', SeatMapFixture::SEAT_MAP_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('seat_maps')->where('id', SeatMapFixture::SEAT_MAP_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('seat_maps')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'venue_id' => VenueFixture::VENUE_A,
            'name' => 'Forged Seat Map',
            'layout' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from seat_maps'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(SeatMapFixture::SEAT_MAP_A);
});

it('lets nodia_platform read every tenant\'s seat maps without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('seat_maps')->pluck('id'));

    expect($ids->all())->toContain(SeatMapFixture::SEAT_MAP_A, SeatMapFixture::SEAT_MAP_B);
});

it('rejects a nodia_platform write with no tenant asserted, seat_maps has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('seat_maps')->where('id', SeatMapFixture::SEAT_MAP_A)->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);
});
