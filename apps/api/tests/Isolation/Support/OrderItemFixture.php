<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\EventCatalog\Models\TicketType;
use App\Orders\Models\OrderItem;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on OrderFixture with one ticket type and one order item per
 * tenant, mirroring HoldItemFixture's shape over HoldFixture (stage-07
 * plan, task breakdown item 2).
 */
final class OrderItemFixture
{
    public const ITEM_A = '019797f4-0000-7000-8000-0000000000d1';

    public const ITEM_B = '019797f4-0000-7000-8000-0000000000d2';

    public const TICKET_TYPE_A = '019797f4-0000-7000-8000-0000000000e1';

    public const TICKET_TYPE_B = '019797f4-0000-7000-8000-0000000000e2';

    public static function seed(): void
    {
        OrderFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            TicketType::factory()->create([
                'id' => self::TICKET_TYPE_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'event_id' => EventFixture::EVENT_A,
            ]);

            OrderItem::factory()->create([
                'id' => self::ITEM_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'order_id' => OrderFixture::ORDER_A,
                'ticket_type_id' => self::TICKET_TYPE_A,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            TicketType::factory()->create([
                'id' => self::TICKET_TYPE_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'event_id' => EventFixture::EVENT_B,
            ]);

            OrderItem::factory()->create([
                'id' => self::ITEM_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'order_id' => OrderFixture::ORDER_B,
                'ticket_type_id' => self::TICKET_TYPE_B,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('order_items')->where('id', self::ITEM_A)->delete();
            DB::table('ticket_types')->where('id', self::TICKET_TYPE_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('order_items')->where('id', self::ITEM_B)->delete();
            DB::table('ticket_types')->where('id', self::TICKET_TYPE_B)->delete();
        });

        OrderFixture::clean();
    }
}
