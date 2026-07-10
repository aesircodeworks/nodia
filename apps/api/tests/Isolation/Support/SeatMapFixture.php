<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\EventCatalog\Models\SeatMap;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on VenueFixture's one venue per tenant with one seat map row each
 * (stage-05b plan, TDD sequencing Slice 1: "two-tenant fixture proves
 * cross-tenant SELECT, UPDATE, and DELETE against seat_maps rows return
 * nothing and affect no rows under RLS"). seat_maps carries no self-read
 * or platform-write extras (standard single-table policy, mirroring
 * venues and ticket_types), so this fixture mirrors TicketTypeFixture's
 * own shape.
 */
final class SeatMapFixture
{
    public const SEAT_MAP_A = '019797f2-0000-7000-8000-0000000000c3';

    public const SEAT_MAP_B = '019797f2-0000-7000-8000-0000000000c4';

    public static function seed(): void
    {
        VenueFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            SeatMap::factory()->create([
                'id' => self::SEAT_MAP_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'venue_id' => VenueFixture::VENUE_A,
                'name' => 'Seat Map A',
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            SeatMap::factory()->create([
                'id' => self::SEAT_MAP_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'venue_id' => VenueFixture::VENUE_B,
                'name' => 'Seat Map B',
            ]);
        });
    }

    public static function clean(): void
    {
        // Deleted under nodia_app with each row's own tenant asserted:
        // seat_maps has no platform write policy, so nodia_platform alone
        // could not see past its tenant_isolation policy either.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('seat_maps')->where('id', self::SEAT_MAP_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('seat_maps')->where('id', self::SEAT_MAP_B)->delete();
        });

        VenueFixture::clean();
    }
}
