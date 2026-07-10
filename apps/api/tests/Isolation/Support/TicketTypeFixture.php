<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\EventCatalog\Models\TicketType;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on EventFixture's one virtual event per tenant with one ticket
 * type row each (stage-05a plan, task breakdown item 7: "isolation tests"
 * against ticket_types). ticket_types carries no self-read or
 * platform-write extras (standard single-table policy, mirroring venues
 * and events), so this fixture mirrors EventFixture's own shape.
 */
final class TicketTypeFixture
{
    public const TICKET_TYPE_A = '019797f2-0000-7000-8000-0000000000f1';

    public const TICKET_TYPE_B = '019797f2-0000-7000-8000-0000000000f2';

    public static function seed(): void
    {
        EventFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            TicketType::factory()->create([
                'id' => self::TICKET_TYPE_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'event_id' => EventFixture::EVENT_A,
                'name' => 'General Admission A',
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            TicketType::factory()->create([
                'id' => self::TICKET_TYPE_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'event_id' => EventFixture::EVENT_B,
                'name' => 'General Admission B',
            ]);
        });
    }

    public static function clean(): void
    {
        // Deleted under nodia_app with each row's own tenant asserted:
        // ticket_types has no platform write policy, so nodia_platform
        // alone could not see past its tenant_isolation policy either.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('ticket_types')->where('id', self::TICKET_TYPE_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('ticket_types')->where('id', self::TICKET_TYPE_B)->delete();
        });

        EventFixture::clean();
    }
}
