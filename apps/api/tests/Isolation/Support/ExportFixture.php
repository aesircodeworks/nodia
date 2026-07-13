<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Models\User;
use App\Reporting\Models\Export;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * One exports row per tenant, mirroring ReportDailySalesFixture's own
 * shape (stage-11 plan, TDD sequencing Slice 8: "Isolation: exports
 * cross-tenant denial"). exports carries no self-read or platform-write
 * extras beyond the standard single-table policy and no natural business
 * key to make unique, unlike the three projection tables, so this
 * fixture skips the unique-constraint case those fixtures cover. The
 * requesting user is created inline under nodia_platform, the same
 * posture CheckInFixture uses for users, since users carries no
 * tenant_id.
 */
final class ExportFixture
{
    public const ROW_A = '019797fb-0000-7000-8000-0000000000c1';

    public const ROW_B = '019797fb-0000-7000-8000-0000000000c2';

    public const USER_A = '019797fb-0000-7000-8000-0000000000d1';

    public const USER_B = '019797fb-0000-7000-8000-0000000000d2';

    public static function seed(): void
    {
        TenantFixture::seed();

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            User::factory()->create(['id' => self::USER_A, 'email' => 'export-a@export-fixture.example']);
            User::factory()->create(['id' => self::USER_B, 'email' => 'export-b@export-fixture.example']);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Export::factory()->create([
                'id' => self::ROW_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'requested_by_user_id' => self::USER_A,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Export::factory()->create([
                'id' => self::ROW_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'requested_by_user_id' => self::USER_B,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('exports')->where('id', self::ROW_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('exports')->where('id', self::ROW_B)->delete();
        });

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            DB::table('users')->whereIn('id', [self::USER_A, self::USER_B])->delete();
        });

        TenantFixture::clean();
    }
}
