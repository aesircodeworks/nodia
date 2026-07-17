<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\EventCatalog\Models\Event;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TenantFixture's two tenants with one virtual event row per
 * tenant (stage-05a plan, task breakdown item 4: "the same probes against
 * events"). Virtual so the fixture needs no Venue row to satisfy
 * events_venue_or_url. events carries no self-read or platform-write
 * extras (standard single-table policy, mirroring venues), so this
 * fixture mirrors VenueFixture's own shape.
 */
final class EventFixture
{
    public const EVENT_A = '019797f2-0000-7000-8000-0000000000e1';

    public const EVENT_B = '019797f2-0000-7000-8000-0000000000e2';

    public static function seed(): void
    {
        TenantFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Event::factory()->create([
                'id' => self::EVENT_A,
                'tenant_id' => TenantFixture::TENANT_A,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Event::factory()->create([
                'id' => self::EVENT_B,
                'tenant_id' => TenantFixture::TENANT_B,
            ]);
        });
    }

    public static function clean(): void
    {
        // Deleted under nodia_app with each row's own tenant asserted:
        // events has no platform write policy, so nodia_platform alone
        // could not see past its tenant_isolation policy either.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('events')->where('id', self::EVENT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('events')->where('id', self::EVENT_B)->delete();
        });

        TenantFixture::clean();
    }
}
