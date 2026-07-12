<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\CheckIn\Enums\CheckInResult;
use App\CheckIn\Models\CheckIn;
use App\Models\User;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TicketFixture with one accepted check-in per tenant. The
 * scanning user is created inline under nodia_platform, the same
 * posture IdentityFixture uses for users, since users carries no
 * tenant_id (stage-09 plan, Data model "check_ins").
 */
final class CheckInFixture
{
    public const CHECK_IN_A = '019797f6-0000-7000-8000-0000000000a1';

    public const CHECK_IN_B = '019797f6-0000-7000-8000-0000000000a2';

    public const USER_A = '019797f6-0000-7000-8000-0000000000b1';

    public const USER_B = '019797f6-0000-7000-8000-0000000000b2';

    public static function seed(): void
    {
        TicketFixture::seed();

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            User::factory()->create(['id' => self::USER_A, 'email' => 'checkin-a@check-in-fixture.example']);
            User::factory()->create(['id' => self::USER_B, 'email' => 'checkin-b@check-in-fixture.example']);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            CheckIn::factory()->create([
                'id' => self::CHECK_IN_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'ticket_id' => TicketFixture::TICKET_A,
                'event_id' => EventFixture::EVENT_A,
                'user_id' => self::USER_A,
                'result' => CheckInResult::Accepted,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            CheckIn::factory()->create([
                'id' => self::CHECK_IN_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'ticket_id' => TicketFixture::TICKET_B,
                'event_id' => EventFixture::EVENT_B,
                'user_id' => self::USER_B,
                'result' => CheckInResult::Accepted,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('check_ins')->where('id', self::CHECK_IN_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('check_ins')->where('id', self::CHECK_IN_B)->delete();
        });

        actingAsRole(Rls::PLATFORM_ROLE, null, function (): void {
            DB::table('users')->whereIn('id', [self::USER_A, self::USER_B])->delete();
        });

        TicketFixture::clean();
    }
}
