<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\Identity\Models\Customer;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on EventFixture with one customer and one pending order per
 * tenant, the customers created inline like HoldItemFixture creates its
 * ticket types so clean() tears everything down before the tenants go
 * (stage-07 plan, task breakdown item 2). orders carries the standard
 * single-table policy with no platform-write extras, mirroring
 * HoldFixture's shape. hold_id values are fixture-local uuids: orders
 * keeps no FK into Inventory's table by design (stage-07 plan, Data
 * model "orders").
 */
final class OrderFixture
{
    public const ORDER_A = '019797f4-0000-7000-8000-0000000000a1';

    public const ORDER_B = '019797f4-0000-7000-8000-0000000000a2';

    public const CUSTOMER_A = '019797f4-0000-7000-8000-0000000000c1';

    public const CUSTOMER_B = '019797f4-0000-7000-8000-0000000000c2';

    public const HOLD_A = '019797f4-0000-7000-8000-0000000000b1';

    public const HOLD_B = '019797f4-0000-7000-8000-0000000000b2';

    public static function seed(): void
    {
        EventFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Customer::factory()->create([
                'id' => self::CUSTOMER_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'email' => 'order-a@order-fixture.example',
            ]);

            Order::factory()->create([
                'id' => self::ORDER_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'customer_id' => self::CUSTOMER_A,
                'event_id' => EventFixture::EVENT_A,
                'hold_id' => self::HOLD_A,
                'status' => OrderStatus::Pending,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Customer::factory()->create([
                'id' => self::CUSTOMER_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'email' => 'order-b@order-fixture.example',
            ]);

            Order::factory()->create([
                'id' => self::ORDER_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'customer_id' => self::CUSTOMER_B,
                'event_id' => EventFixture::EVENT_B,
                'hold_id' => self::HOLD_B,
                'status' => OrderStatus::Pending,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('orders')->where('id', self::ORDER_A)->delete();
            DB::table('customers')->where('id', self::CUSTOMER_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('orders')->where('id', self::ORDER_B)->delete();
            DB::table('customers')->where('id', self::CUSTOMER_B)->delete();
        });

        EventFixture::clean();
    }
}
