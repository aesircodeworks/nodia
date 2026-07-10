<?php

use App\Support\Database\Rls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Isolation\Support\MediaFixture;
use Tests\Isolation\Support\TenantFixture;

use function Tests\Isolation\Support\actingAsRole;

/*
 * media is the standard Rls::applyTenantPolicies posture with the
 * platform write policy also enabled (stage-05c plan Data model and the
 * media migration's own docblock): tenant-scope SELECT, UPDATE, and
 * DELETE are confined to the caller's own tenant like every other
 * tenant-scoped table (venues, ticket_types), and nodia_platform can
 * additionally write under any tenant_id, unlike those tables, because
 * Stage 5c task 5's tenant branding logo upload runs entirely under the
 * platform posture (system-design 4.3). Written first per the master
 * plan's TDD sequencing (stage-05c plan, TDD sequencing, Slice 1:
 * "a two-tenant fixture proves cross-tenant SELECT, UPDATE, and DELETE on
 * media return zero rows under RLS"), failing until the media migration
 * and its RLS policy land.
 */

beforeEach(fn () => MediaFixture::seed());

afterEach(fn () => MediaFixture::clean());

it('shows a tenant only its own media row', function () {
    $ids = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('media')->pluck('id'),
    );

    expect($ids->all())->toBe([MediaFixture::MEDIA_A]);
});

it('makes a cross-tenant select return zero rows', function () {
    $row = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('media')->where('id', MediaFixture::MEDIA_B)->first(),
    );

    expect($row)->toBeNull();
});

it('makes a cross-tenant update affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('media')
            ->where('id', MediaFixture::MEDIA_B)
            ->update(['name' => 'Hijacked']),
    );

    expect($affected)->toBe(0);

    $name = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('media')->where('id', MediaFixture::MEDIA_B)->value('name'),
    );

    expect($name)->not->toBe('Hijacked');
});

it('makes a cross-tenant delete affect zero rows', function () {
    $affected = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('media')->where('id', MediaFixture::MEDIA_B)->delete(),
    );

    expect($affected)->toBe(0);

    $exists = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_B,
        fn () => DB::table('media')->where('id', MediaFixture::MEDIA_B)->exists(),
    );

    expect($exists)->toBeTrue();
});

it('rejects an insert bearing a foreign tenant_id through WITH CHECK', function () {
    expect(fn () => actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('media')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => TenantFixture::TENANT_B,
            'model_type' => 'App\\Tenancy\\Models\\Tenant',
            'model_id' => TenantFixture::TENANT_B,
            'uuid' => Str::uuid()->toString(),
            'collection_name' => 'logo',
            'name' => 'Forged Media',
            'file_name' => 'forged.png',
            'disk' => 'media',
            'size' => 1,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ))->toThrow(QueryException::class, 'row-level security');
});

it('isolates a raw sql query that bypasses all eloquent scoping', function () {
    $rows = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::select('select * from media'),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(MediaFixture::MEDIA_A);
});

it('lets nodia_platform read every tenant\'s media without any tenant context', function () {
    $ids = actingAsRole(Rls::PLATFORM_ROLE, null, fn () => DB::table('media')->pluck('id'));

    expect($ids->all())->toContain(MediaFixture::MEDIA_A, MediaFixture::MEDIA_B);
});

it('lets nodia_platform write under any tenant_id with no matching tenant context', function () {
    $affected = actingAsRole(
        Rls::PLATFORM_ROLE,
        null,
        fn () => DB::table('media')
            ->where('id', MediaFixture::MEDIA_A)
            ->update(['name' => 'Platform-edited']),
    );

    expect($affected)->toBe(1);

    $name = actingAsRole(
        Rls::APP_ROLE,
        TenantFixture::TENANT_A,
        fn () => DB::table('media')->where('id', MediaFixture::MEDIA_A)->value('name'),
    );

    expect($name)->toBe('Platform-edited');
});
