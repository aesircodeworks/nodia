<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Orders\Models\Ticket;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on OrderItemFixture with one issued ticket per tenant
 * (stage-07 plan, task breakdown item 7), mirroring OrderItemFixture's
 * shape over OrderFixture.
 */
final class TicketFixture
{
    public const TICKET_A = '019797f4-0000-7000-8000-0000000000f1';

    public const TICKET_B = '019797f4-0000-7000-8000-0000000000f2';

    public static function seed(): void
    {
        OrderItemFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Ticket::factory()->create([
                'id' => self::TICKET_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'order_id' => OrderFixture::ORDER_A,
                'ticket_type_id' => OrderItemFixture::TICKET_TYPE_A,
                'event_id' => EventFixture::EVENT_A,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Ticket::factory()->create([
                'id' => self::TICKET_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'order_id' => OrderFixture::ORDER_B,
                'ticket_type_id' => OrderItemFixture::TICKET_TYPE_B,
                'event_id' => EventFixture::EVENT_B,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('tickets')->where('id', self::TICKET_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('tickets')->where('id', self::TICKET_B)->delete();
        });

        OrderItemFixture::clean();
    }
}
