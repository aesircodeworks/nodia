<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\EventCatalog\Models\TicketType;
use App\Inventory\Models\HoldItem;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on HoldFixture: one hold_items row per tenant, tying each
 * tenant's hold to a ticket type on its own event (stage-06 plan, task
 * breakdown item 4: "Isolation (first): ... hold_items probes green").
 * hold_items carries no self-read or platform-write extras (standard
 * single-table policy). The ticket type is created here directly, not
 * through TicketTypeFixture::seed(), which would re-seed EventFixture
 * (and TenantFixture underneath it) a second time and collide on the
 * primary keys HoldFixture::seed() already created.
 */
final class HoldItemFixture
{
    public const ITEM_A = '019797f3-0000-7000-8000-0000000000f3';

    public const ITEM_B = '019797f3-0000-7000-8000-0000000000f4';

    public const TICKET_TYPE_A = '019797f3-0000-7000-8000-0000000000f5';

    public const TICKET_TYPE_B = '019797f3-0000-7000-8000-0000000000f6';

    public static function seed(): void
    {
        HoldFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            TicketType::factory()->create([
                'id' => self::TICKET_TYPE_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'event_id' => EventFixture::EVENT_A,
            ]);

            HoldItem::factory()->create([
                'id' => self::ITEM_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'hold_id' => HoldFixture::HOLD_A,
                'ticket_type_id' => self::TICKET_TYPE_A,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            TicketType::factory()->create([
                'id' => self::TICKET_TYPE_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'event_id' => EventFixture::EVENT_B,
            ]);

            HoldItem::factory()->create([
                'id' => self::ITEM_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'hold_id' => HoldFixture::HOLD_B,
                'ticket_type_id' => self::TICKET_TYPE_B,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('hold_items')->where('id', self::ITEM_A)->delete();
            DB::table('ticket_types')->where('id', self::TICKET_TYPE_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('hold_items')->where('id', self::ITEM_B)->delete();
            DB::table('ticket_types')->where('id', self::TICKET_TYPE_B)->delete();
        });

        HoldFixture::clean();
    }
}
