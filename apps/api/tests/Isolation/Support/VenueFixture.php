<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\EventCatalog\Models\Venue;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TenantFixture's two tenants with one venue row per tenant,
 * the two-tenant fixture stage-05a plan task breakdown item 3 names ("a
 * two-tenant fixture proves cross-tenant SELECT, UPDATE, and DELETE
 * against venues return nothing and affect no rows under RLS"). venues
 * carries no self-read or platform-write extras (standard single-table
 * policy, mirroring customers), so this fixture mirrors CustomerFixture's
 * own shape.
 */
final class VenueFixture
{
    public const VENUE_A = '019797f2-0000-7000-8000-0000000000d1';

    public const VENUE_B = '019797f2-0000-7000-8000-0000000000d2';

    public static function seed(): void
    {
        TenantFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Venue::factory()->create([
                'id' => self::VENUE_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'name' => 'Venue A',
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Venue::factory()->create([
                'id' => self::VENUE_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'name' => 'Venue B',
            ]);
        });
    }

    public static function clean(): void
    {
        // Deleted under nodia_app with each row's own tenant asserted:
        // venues has no platform write policy, so nodia_platform alone
        // could not see past its tenant_isolation policy either.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('venues')->where('id', self::VENUE_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('venues')->where('id', self::VENUE_B)->delete();
        });

        TenantFixture::clean();
    }
}
