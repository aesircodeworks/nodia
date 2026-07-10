<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\TenantFixture;
use Tests\Isolation\Support\VenueFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * venues is the standard Rls::applyTenantPolicies posture with no extra
 * policies (stage-05a plan Data model: venue mutation is tenant admin
 * surface, not platform surface), the same shape as customers. Written
 * first per the master plan's TDD sequencing (stage-05a plan, TDD
 * sequencing, Slice 1: "Isolation (first): two-tenant fixture proves
 * cross-tenant SELECT, UPDATE, and DELETE against venues return nothing
 * and affect no rows under RLS"), failing until the venues migration and
 * its RLS policy land.
 */

beforeEach(fn () => VenueFixture::seed());

afterEach(fn () => VenueFixture::clean());

it('shows a tenant only its own venue row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('venues')->pluck('id'),
    );

    expect($ids->all())->toBe([VenueFixture::VENUE_A]);
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('venues')
            ->where('id', VenueFixture::VENUE_B)
            ->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);

    $name = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('venues')->where('id', VenueFixture::VENUE_B)->value('name'),
    );

    expect($name)->not->toBe('Hijacked');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('venues')->where('id', VenueFixture::VENUE_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('venues')->where('id', VenueFixture::VENUE_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('venues')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'name' => 'Forged Venue',
            'address' => '1 Forged St',
            'city' => 'Nowhere',
            'country' => 'US',
            'capacity' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('rejects an insert violating the capacity CHECK regardless of tenant context', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('venues')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_A,
            'name' => 'Zero Capacity Venue',
            'address' => '1 Empty St',
            'city' => 'Nowhere',
            'country' => 'US',
            'capacity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'venues_capacity_positive');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from venues'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(VenueFixture::VENUE_A);
});

it('lets nodia_platform read every tenant\'s venues without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('venues')->pluck('id'));

    expect($ids->all())->toContain(VenueFixture::VENUE_A, VenueFixture::VENUE_B);
});

it('rejects a nodia_platform write with no tenant asserted, venues has no platform write policy', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('venues')->where('id', VenueFixture::VENUE_A)->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);
});
