<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Reporting\Models\EventFinance;
use App\Support\Database\Rls;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Builds on EventFixture's one event per tenant with one
 * report_event_finance row each (stage-11 plan, TDD sequencing Slice 3:
 * "Isolation: report_event_finance cross-tenant denial"). No self-read
 * or platform-write extras beyond the standard single-table policy
 * (mirroring purchase_counters and report_daily_sales), so this fixture
 * mirrors ReportDailySalesFixture's own posture.
 */
final class ReportEventFinanceFixture
{
    public const ROW_A = '019797fa-0000-7000-8000-0000000000b1';

    public const ROW_B = '019797fa-0000-7000-8000-0000000000b2';

    public static function seed(): void
    {
        EventFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            EventFinance::factory()->create([
                'id' => self::ROW_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'event_id' => EventFixture::EVENT_A,
                'orders_paid_count' => 1,
                'gross' => Money::of(1000, 'USD'),
                'tenant_net' => Money::of(1000, 'USD'),
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            EventFinance::factory()->create([
                'id' => self::ROW_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'event_id' => EventFixture::EVENT_B,
                'orders_paid_count' => 1,
                'gross' => Money::of(1000, 'USD'),
                'tenant_net' => Money::of(1000, 'USD'),
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('report_event_finance')->where('id', self::ROW_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('report_event_finance')->where('id', self::ROW_B)->delete();
        });

        EventFixture::clean();
    }
}
