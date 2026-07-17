<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\EventCatalog\Models\Seat;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on SeatMapFixture's one seat map per tenant with one seat row
 * each (stage-05b plan, TDD sequencing Slice 1: "same matrix for seats").
 * seats carries no self-read or platform-write extras (standard
 * single-table policy, mirroring seat_maps and ticket_types), so this
 * fixture mirrors TicketTypeFixture's own shape.
 */
final class SeatFixture
{
    public const SEAT_A = '019797f2-0000-7000-8000-0000000000c5';

    public const SEAT_B = '019797f2-0000-7000-8000-0000000000c6';

    public static function seed(): void
    {
        SeatMapFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Seat::factory()->create([
                'id' => self::SEAT_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'seat_map_id' => SeatMapFixture::SEAT_MAP_A,
                'section' => 'A',
                'row' => '1',
                'number' => '1',
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Seat::factory()->create([
                'id' => self::SEAT_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'seat_map_id' => SeatMapFixture::SEAT_MAP_B,
                'section' => 'A',
                'row' => '1',
                'number' => '1',
            ]);
        });
    }

    public static function clean(): void
    {
        // Deleted under nodia_app with each row's own tenant asserted:
        // seats has no platform write policy, so nodia_platform alone
        // could not see past its tenant_isolation policy either.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('seats')->where('id', self::SEAT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('seats')->where('id', self::SEAT_B)->delete();
        });

        SeatMapFixture::clean();
    }
}
