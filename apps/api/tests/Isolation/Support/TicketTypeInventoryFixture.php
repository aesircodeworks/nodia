<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Inventory\Models\TicketTypeInventory;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TicketTypeFixture's one ticket type per tenant with one
 * ticket_type_inventory counter row each (stage-06 plan, task breakdown
 * item 2: "Isolation (first): the ticket_type_inventory probe from slice
 * 0"). ticket_type_inventory carries no self-read or platform-write
 * extras (standard single-table policy, mirroring ticket_types), so this
 * fixture mirrors TicketTypeFixture's own shape.
 */
final class TicketTypeInventoryFixture
{
    public const INVENTORY_A = '019797f2-0000-7000-8000-0000000000f3';

    public const INVENTORY_B = '019797f2-0000-7000-8000-0000000000f4';

    public static function seed(): void
    {
        TicketTypeFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            TicketTypeInventory::factory()->create([
                'id' => self::INVENTORY_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A,
                'quantity' => 100,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            TicketTypeInventory::factory()->create([
                'id' => self::INVENTORY_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_B,
                'quantity' => 100,
            ]);
        });
    }

    public static function clean(): void
    {
        // Deleted under nodia_app with each row's own tenant asserted:
        // ticket_type_inventory has no platform write policy, so
        // nodia_platform alone could not see past its tenant_isolation
        // policy either.
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('ticket_type_inventory')->where('id', self::INVENTORY_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('ticket_type_inventory')->where('id', self::INVENTORY_B)->delete();
        });

        TicketTypeFixture::clean();
    }
}
