<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Inventory\Enums\HoldStatus;
use App\Inventory\Models\Hold;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on EventFixture's one event per tenant with one active hold
 * each (stage-06 plan, task breakdown item 4: "Isolation (first): holds
 * ... probes green"). holds carries no self-read or platform-write
 * extras (standard single-table policy, mirroring ticket_types), so this
 * fixture mirrors TicketTypeFixture's own shape.
 */
final class HoldFixture
{
    public const HOLD_A = '019797f3-0000-7000-8000-0000000000f1';

    public const HOLD_B = '019797f3-0000-7000-8000-0000000000f2';

    public static function seed(): void
    {
        EventFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Hold::factory()->create([
                'id' => self::HOLD_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'event_id' => EventFixture::EVENT_A,
                'status' => HoldStatus::Active,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Hold::factory()->create([
                'id' => self::HOLD_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'event_id' => EventFixture::EVENT_B,
                'status' => HoldStatus::Active,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('holds')->where('id', self::HOLD_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('holds')->where('id', self::HOLD_B)->delete();
        });

        EventFixture::clean();
    }
}
