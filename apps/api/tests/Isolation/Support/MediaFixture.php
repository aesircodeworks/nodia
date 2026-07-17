<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Support\Database\Rls;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds on TenantFixture's two tenants with one media row per tenant, the
 * two-tenant fixture stage-05c plan task breakdown item 1 / TDD sequencing
 * Slice 1 names ("a two-tenant fixture proves cross-tenant SELECT, UPDATE,
 * and DELETE on media return zero rows under RLS"). Rows are seeded with
 * raw DB::table() calls rather than the Media model's own factory: the
 * model's tenant-stamping hook needs a live TenantContext
 * (App\Support\Tenancy), and this fixture only ever runs under
 * actingAsRole's raw SQL-session posture (SET LOCAL ROLE and app.tenant_id
 * with no PHP-level TenantContext entered), the same posture every other
 * isolation fixture in this suite uses. model_type/model_id point at
 * Tenant itself rather than a seeded Event, because this table's own
 * isolation properties do not depend on which polymorphic owner a row
 * belongs to and TenantFixture already provides two tenant ids to use.
 */
final class MediaFixture
{
    public const MEDIA_A = '019797f3-0000-7000-8000-0000000000e1';

    public const MEDIA_B = '019797f3-0000-7000-8000-0000000000e2';

    public static function seed(): void
    {
        TenantFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('media')->insert(self::row(self::MEDIA_A, TenantFixture::TENANT_A, 'Media A'));
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('media')->insert(self::row(self::MEDIA_B, TenantFixture::TENANT_B, 'Media B'));
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('media')->where('id', self::MEDIA_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('media')->where('id', self::MEDIA_B)->delete();
        });

        TenantFixture::clean();
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $id, string $tenantId, string $name): array
    {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'model_type' => Tenant::class,
            'model_id' => $tenantId,
            'uuid' => Str::uuid()->toString(),
            'collection_name' => 'logo',
            'name' => $name,
            'file_name' => 'logo.png',
            'mime_type' => 'image/png',
            'disk' => 'media',
            'conversions_disk' => 'media',
            'size' => 1024,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
