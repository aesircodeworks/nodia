<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Reporting\Models\DailySales;
use App\Support\Database\Rls;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TicketTypeFixture's one event and ticket type per tenant with
 * one report_daily_sales row each (stage-11 plan, TDD sequencing Slice 1:
 * "Isolation: tenant A cannot read or write tenant B's report_daily_sales
 * rows under the app role"). report_daily_sales carries no self-read or
 * platform-write extras beyond the standard single-table policy
 * (mirroring purchase_counters' own shape), so this fixture mirrors
 * PurchaseCounterFixture's own posture.
 */
final class ReportDailySalesFixture
{
    public const ROW_A = '019797f9-0000-7000-8000-0000000000a1';

    public const ROW_B = '019797f9-0000-7000-8000-0000000000a2';

    public static function seed(): void
    {
        TicketTypeFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DailySales::factory()->create([
                'id' => self::ROW_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'event_id' => EventFixture::EVENT_A,
                'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A,
                'sales_date' => '2026-07-13',
                'tickets_issued_count' => 1,
                'gross' => Money::of(1000, 'USD'),
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DailySales::factory()->create([
                'id' => self::ROW_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'event_id' => EventFixture::EVENT_B,
                'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_B,
                'sales_date' => '2026-07-13',
                'tickets_issued_count' => 1,
                'gross' => Money::of(1000, 'USD'),
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('report_daily_sales')->where('id', self::ROW_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('report_daily_sales')->where('id', self::ROW_B)->delete();
        });

        TicketTypeFixture::clean();
    }
}
