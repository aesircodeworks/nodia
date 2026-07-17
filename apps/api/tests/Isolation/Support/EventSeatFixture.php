<?php

declare(strict_types=1);

namespace Tests\Isolation\Support;

use App\EventCatalog\Models\Event;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use App\Support\Database\Rls;
use Illuminate\Support\Facades\DB;

/**
 * Builds on SeatFixture: one event_seats row per tenant, tying each
 * tenant's materialized seat to an event at its own venue (stage-06
 * plan, Slice 5, task breakdown item 9's slice 0 probe, merged with this
 * task). The event is created here directly, not through
 * EventFixture::seed(), which would re-seed TenantFixture a second time
 * and collide on the primary keys SeatFixture::seed() already created
 * underneath it, mirroring HoldItemFixture's own precedent for
 * ticket_types. event_seats carries no self-read or platform-write
 * extras (standard single-table policy, mirroring holds and
 * ticket_types), so this fixture mirrors HoldFixture's own shape.
 */
final class EventSeatFixture
{
    public const EVENT_A = '019797f4-0000-7000-8000-0000000000d3';

    public const EVENT_B = '019797f4-0000-7000-8000-0000000000d4';

    public const EVENT_SEAT_A = '019797f4-0000-7000-8000-0000000000d1';

    public const EVENT_SEAT_B = '019797f4-0000-7000-8000-0000000000d2';

    public static function seed(): void
    {
        SeatFixture::seed();

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            Event::factory()->create([
                'id' => self::EVENT_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'venue_id' => VenueFixture::VENUE_A,
                'is_virtual' => false,
                'virtual_event_url' => null,
            ]);

            EventSeat::factory()->create([
                'id' => self::EVENT_SEAT_A,
                'tenant_id' => TenantFixture::TENANT_A,
                'event_id' => self::EVENT_A,
                'seat_id' => SeatFixture::SEAT_A,
                'status' => EventSeatStatus::Available,
            ]);
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            Event::factory()->create([
                'id' => self::EVENT_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'venue_id' => VenueFixture::VENUE_B,
                'is_virtual' => false,
                'virtual_event_url' => null,
            ]);

            EventSeat::factory()->create([
                'id' => self::EVENT_SEAT_B,
                'tenant_id' => TenantFixture::TENANT_B,
                'event_id' => self::EVENT_B,
                'seat_id' => SeatFixture::SEAT_B,
                'status' => EventSeatStatus::Available,
            ]);
        });
    }

    public static function clean(): void
    {
        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_A, function (): void {
            DB::table('event_seats')->where('id', self::EVENT_SEAT_A)->delete();
            DB::table('events')->where('id', self::EVENT_A)->delete();
        });

        actingAsRole(Rls::APP_ROLE, TenantFixture::TENANT_B, function (): void {
            DB::table('event_seats')->where('id', self::EVENT_SEAT_B)->delete();
            DB::table('events')->where('id', self::EVENT_B)->delete();
        });

        SeatFixture::clean();
    }
}
