<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Identity\Models\Customer;
use App\Inventory\Models\PurchaseCounter;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on TicketTypeFixture's one ticket type per tenant with one
 * customer and one purchase_counters row each (stage-10 plan, TDD
 * sequencing Slice 3: "Isolation (first): purchase_counters probes under
 * the two-tenant fixture"). The customer is created here directly, not
 * through CustomerFixture::seed(), which would re-seed TenantFixture a
 * second time and collide on the primary keys TicketTypeFixture::seed()
 * already created underneath it (mirroring HoldItemFixture's own reason
 * for creating its ticket type directly). purchase_counters carries no
 * self-read or platform-write extras (standard single-table policy).
 */
final class PurchaseCounterFixture
{
    public const COUNTER_A = '019797f8-0000-7000-8000-0000000000f1';

    public const COUNTER_B = '019797f8-0000-7000-8000-0000000000f2';

    public const CUSTOMER_A = '019797f8-0000-7000-8000-0000000000f3';

    public const CUSTOMER_B = '019797f8-0000-7000-8000-0000000000f4';

    public static function seed(): void
    {
        TicketTypeFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Customer::factory()->create([
                'id' => self::CUSTOMER_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'email' => 'purchase-counter-a@fixture.example',
            ]);

            PurchaseCounter::factory()->create([
                'id' => self::COUNTER_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'customer_id' => self::CUSTOMER_A,
                'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_A,
                'quantity' => 1,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Customer::factory()->create([
                'id' => self::CUSTOMER_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'email' => 'purchase-counter-b@fixture.example',
            ]);

            PurchaseCounter::factory()->create([
                'id' => self::COUNTER_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'customer_id' => self::CUSTOMER_B,
                'ticket_type_id' => TicketTypeFixture::TICKET_TYPE_B,
                'quantity' => 1,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('purchase_counters')->where('id', self::COUNTER_A)->delete();
            DB::table('customers')->where('id', self::CUSTOMER_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('purchase_counters')->where('id', self::COUNTER_B)->delete();
            DB::table('customers')->where('id', self::CUSTOMER_B)->delete();
        });

        TicketTypeFixture::clean();
    }
}
