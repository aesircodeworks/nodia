<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Reporting\Models\EventAttendance;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TicketTypeFixture's one event and ticket type per tenant
 * with one report_event_attendance row each (stage-11 plan, TDD
 * sequencing Slice 5: "Isolation: report_event_attendance cross-tenant
 * denial"). No self-read or platform-write extras beyond the standard
 * single-table policy, mirroring ReportDailySalesFixture's own posture.
 */
final class ReportEventAttendanceFixture
{
    public const ROW_A = '019797fb-0000-7000-8000-0000000000c1';

    public const ROW_B = '019797fb-0000-7000-8000-0000000000c2';

    public static function seed(): void
    {
        TicketTypeFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            EventAttendance::factory()->create([
                'id' => self::ROW_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'event_id' => EventFixture::EVENT_A,
                'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A,
                'checked_in_count' => 1,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            EventAttendance::factory()->create([
                'id' => self::ROW_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'event_id' => EventFixture::EVENT_B,
                'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_B,
                'checked_in_count' => 1,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('report_event_attendance')->where('id', self::ROW_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('report_event_attendance')->where('id', self::ROW_B)->delete();
        });

        TicketTypeFixture::clean();
    }
}
