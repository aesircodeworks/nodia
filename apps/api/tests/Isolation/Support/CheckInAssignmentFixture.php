<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\CheckIn\Models\CheckInAssignment;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on EventFixture with one assignment per tenant. The assigned
 * user is created inline under nodia_platform, the same posture
 * CheckInFixture uses for users, since users carries no tenant_id
 * (stage-09 plan, Data model "check_in_assignments").
 */
final class CheckInAssignmentFixture
{
    public const ASSIGNMENT_A = '019797f6-0000-7000-8000-0000000000c1';

    public const ASSIGNMENT_B = '019797f6-0000-7000-8000-0000000000c2';

    public const USER_A = '019797f6-0000-7000-8000-0000000000d1';

    public const USER_B = '019797f6-0000-7000-8000-0000000000d2';

    public static function seed(): void
    {
        EventFixture::seed();

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            User::factory()->create(['id' => self::USER_A, 'email' => 'assignment-a@check-in-assignment-fixture.example']);
            User::factory()->create(['id' => self::USER_B, 'email' => 'assignment-b@check-in-assignment-fixture.example']);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            CheckInAssignment::factory()->create([
                'id' => self::ASSIGNMENT_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'event_id' => EventFixture::EVENT_A,
                'user_id' => self::USER_A,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            CheckInAssignment::factory()->create([
                'id' => self::ASSIGNMENT_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'event_id' => EventFixture::EVENT_B,
                'user_id' => self::USER_B,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('check_in_assignments')->where('id', self::ASSIGNMENT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('check_in_assignments')->where('id', self::ASSIGNMENT_B)->delete();
        });

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            DB::table('users')->whereIn('id', [self::USER_A, self::USER_B])->delete();
        });

        EventFixture::clean();
    }
}
