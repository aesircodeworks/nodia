<?php

use App\EventCatalog\Models\SeatMap;
use App\Identity\Capability;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-06 plan, Slice 5, task breakdown item 9: publishing a seated
 * event through the Stage 5a POST /v1/events/{event}/publish endpoint
 * materializes event_seats; publishing a GA event materializes nothing;
 * a requires_seat ticket type with no seat_map_id is refused with
 * catalog.seat_map_required; deleting a template whose map is
 * materialized fails with catalog.seat_map_in_use.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsManage, Capability::EventsPublish, Capability::SeatMapsManage]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant($this->tenantId, function (): void {
        DB::table('outbox_deliveries')->where('tenant_id', $this->tenantId)->delete();
        DB::table('outbox_events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('memberships')->where('tenant_id', $this->tenantId)->delete();
        DB::table('event_seats')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_type_inventory')->where('tenant_id', $this->tenantId)->delete();
        DB::table('ticket_types')->where('tenant_id', $this->tenantId)->delete();
        DB::table('events')->where('tenant_id', $this->tenantId)->delete();
        DB::table('seats')->where('tenant_id', $this->tenantId)->delete();
        DB::table('seat_maps')->where('tenant_id', $this->tenantId)->delete();
        DB::table('venues')->where('tenant_id', $this->tenantId)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

it('materializes unzoned event_seats when a seated event is published', function () {
    $venueId = $this->postJson('/v1/venues', [
        'name' => 'Grand Arena',
        'address' => '123 Main St',
        'city' => 'Austin',
        'country' => 'US',
        'capacity' => 5000,
    ])->assertCreated()->json('id');

    $seatMapId = $this->postJson("/v1/venues/{$venueId}/seat-maps", [
        'name' => 'Main Floor',
        'layout' => ['rows' => 1],
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => 0, 'position_y' => 0],
            ['section' => 'A', 'row' => '1', 'number' => '2', 'position_x' => 1, 'position_y' => 0],
        ],
    ])->assertCreated()->json('id');

    $eventId = $this->postJson('/v1/events', [
        'name' => ['en' => 'Seated Gala'],
        'description' => ['en' => 'A seated event.'],
        'venue_id' => $venueId,
        'is_virtual' => false,
        'virtual_event_url' => null,
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'America/Chicago',
    ])->assertCreated()->json('id');

    $this->patchJson("/v1/events/{$eventId}", ['seat_map_id' => $seatMapId])->assertOk();

    $this->postJson("/v1/events/{$eventId}/ticket-types", [
        'name' => 'Floor Seating',
        'price' => ['amount' => 10000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        'requires_seat' => true,
    ])->assertCreated()->json('id');

    $this->postJson("/v1/events/{$eventId}/publish")
        ->assertOk()
        ->assertConformsToOpenApi()
        ->assertJsonPath('status', 'published');

    $seats = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSeat::query()->where('event_id', $eventId)->get(),
    );

    expect($seats)->toHaveCount(2);

    foreach ($seats as $seat) {
        expect($seat->status)->toBe(EventSeatStatus::Available)
            ->and($seat->ticket_type_id)->toBeNull()
            ->and($seat->hold_id)->toBeNull();
    }
});

it('materializes nothing when a GA event is published', function () {
    $eventId = $this->postJson('/v1/events', [
        'name' => ['en' => 'GA Gala'],
        'description' => ['en' => 'A GA event.'],
        'venue_id' => null,
        'is_virtual' => true,
        'virtual_event_url' => 'https://example.test/stream',
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'UTC',
    ])->assertCreated()->json('id');

    $this->postJson("/v1/events/{$eventId}/ticket-types", [
        'name' => 'GA',
        'price' => ['amount' => 2000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
    ])->assertCreated();

    $this->postJson("/v1/events/{$eventId}/publish")->assertOk();

    $seatCount = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => EventSeat::query()->where('event_id', $eventId)->count(),
    );

    expect($seatCount)->toBe(0);
});

it('refuses to publish a requires_seat ticket type with no seat_map_id', function () {
    $eventId = $this->postJson('/v1/events', [
        'name' => ['en' => 'Missing Map'],
        'description' => ['en' => 'No seat map.'],
        'venue_id' => null,
        'is_virtual' => true,
        'virtual_event_url' => 'https://example.test/stream',
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'UTC',
    ])->assertCreated()->json('id');

    $this->postJson("/v1/events/{$eventId}/ticket-types", [
        'name' => 'Floor Seating',
        'price' => ['amount' => 10000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        'requires_seat' => true,
    ])->assertCreated();

    $this->postJson("/v1/events/{$eventId}/publish")
        ->assertStatus(409)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'catalog.seat_map_required');
});

it('rejects deleting a seat map whose seats are materialized into event_seats', function () {
    $venueId = $this->postJson('/v1/venues', [
        'name' => 'Grand Arena',
        'address' => '123 Main St',
        'city' => 'Austin',
        'country' => 'US',
        'capacity' => 5000,
    ])->assertCreated()->json('id');

    $seatMapId = $this->postJson("/v1/venues/{$venueId}/seat-maps", [
        'name' => 'Main Floor',
        'layout' => ['rows' => 1],
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => 0, 'position_y' => 0],
        ],
    ])->assertCreated()->json('id');

    $eventId = $this->postJson('/v1/events', [
        'name' => ['en' => 'Seated Gala'],
        'description' => ['en' => 'A seated event.'],
        'venue_id' => $venueId,
        'is_virtual' => false,
        'virtual_event_url' => null,
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'America/Chicago',
    ])->assertCreated()->json('id');

    $this->patchJson("/v1/events/{$eventId}", ['seat_map_id' => $seatMapId])->assertOk();

    $this->postJson("/v1/events/{$eventId}/ticket-types", [
        'name' => 'Floor Seating',
        'price' => ['amount' => 10000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        'requires_seat' => true,
    ])->assertCreated();

    $this->postJson("/v1/events/{$eventId}/publish")->assertOk();

    $response = $this->deleteJson('/v1/seat-maps/'.$seatMapId);

    $response->assertStatus(409)
        ->assertConformsToOpenApi()
        ->assertJsonPath('code', 'catalog.seat_map_in_use');

    expect(app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => SeatMap::query()->whereKey($seatMapId)->exists(),
    ))->toBeTrue();
});
