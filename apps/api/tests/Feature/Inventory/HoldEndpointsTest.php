<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\TicketType;
use App\EventCatalog\Models\Venue;
use App\Identity\Models\Customer;
use App\Inventory\Actions\CommitHold;
use App\Inventory\Actions\ReleaseExpiredHolds;
use App\Inventory\Data\CommitHoldData;
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Models\EventSeat;
use App\Inventory\Models\Hold;
use App\Inventory\Models\PurchaseCounter;
use App\Inventory\Models\TicketTypeInventory;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-06 plan, TDD sequencing Slice 2, task breakdown item 4: POST and
 * GET /v1/storefront/holds, the GA path, mirroring
 * tests/Feature/Identity/CustomerAuthenticationTest.php's own
 * Host-resolution fixture pattern.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('hold_items')->where('tenant_id', $tenantId)->delete();
            DB::table('event_seats')->where('tenant_id', $tenantId)->delete();
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('purchase_counters')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
            DB::table('seats')->where('tenant_id', $tenantId)->delete();
            DB::table('seat_maps')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @return array{tenant: Tenant, host: string}
 */
function holdTenant(): array
{
    return app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function holdEvent(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, 'status' => EventStatus::Published, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function holdTicketType(string $tenantId, string $eventId, int $quantity, array $attributes = []): TicketType
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $quantity, $attributes): TicketType {
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $eventId, ...$attributes]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'held' => 0,
            'sold' => 0,
        ]);

        return $ticketType;
    });
}

/**
 * A seated, zoned ticket type with `$seatCount` available, materialized
 * event_seats rows already assigned to it (mirroring the post-zoning
 * state, stage-06 plan Slice 6, task breakdown item 10).
 *
 * @return array{ticketType: TicketType, seatIds: list<string>}
 */
function holdSeatedTicketType(string $tenantId, string $eventId, int $seatCount): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId, $eventId, $seatCount): array {
        $venue = Venue::factory()->create(['tenant_id' => $tenantId]);
        $seatMap = SeatMap::factory()->create(['tenant_id' => $tenantId, 'venue_id' => $venue->id]);
        $ticketType = TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $eventId, 'requires_seat' => true]);

        DB::table('events')->where('id', $eventId)->update(['venue_id' => $venue->id, 'seat_map_id' => $seatMap->id, 'is_virtual' => false, 'virtual_event_url' => null]);

        TicketTypeInventory::factory()->create([
            'tenant_id' => $tenantId,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $seatCount,
            'held' => 0,
            'sold' => 0,
        ]);

        $seatIds = [];

        for ($i = 0; $i < $seatCount; $i++) {
            $templateSeat = Seat::factory()->create(['tenant_id' => $tenantId, 'seat_map_id' => $seatMap->id, 'section' => 'A', 'row' => '1', 'number' => (string) ($i + 1)]);

            $eventSeat = EventSeat::factory()->create([
                'tenant_id' => $tenantId,
                'event_id' => $eventId,
                'seat_id' => $templateSeat->id,
                'ticket_type_id' => $ticketType->id,
                'status' => EventSeatStatus::Available,
            ]);

            $seatIds[] = $eventSeat->id;
        }

        return ['ticketType' => $ticketType, 'seatIds' => $seatIds];
    });
}

/**
 * @return array{tenant: Tenant, host: string, access_token: string}
 */
function holdCustomerBearer(string $tenantId, string $host): array
{
    app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Customer::factory()->create([
            'tenant_id' => $tenantId,
            'email' => 'buyer@example.com',
            'password' => 'password',
        ]),
    );

    $pair = test()->postJson('http://'.$host.'/v1/auth/customer/token', [
        'email' => 'buyer@example.com',
        'password' => 'password',
    ])->json();

    return $pair;
}

describe('POST /v1/storefront/holds', function (): void {
    it('creates a hold and returns 201 with the wire shape, expires_at 10 minutes out', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);

        $now = now();
        $this->travelTo($now);

        $response = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ]);

        $response->assertStatus(201)->assertConformsToOpenApi();

        $response->assertJsonPath('event_id', $event->id)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('expires_at', $now->copy()->addMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('items.0.ticket_type_id', $ticketType->id)
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('seat_ids', []);

        expect(Str::isUuid($response->json('id')))->toBeTrue();
    });

    it('derives customer_id from the customer bearer token', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);
        $pair = holdCustomerBearer($tenant->id, $host);

        $response = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ], ['Authorization' => 'Bearer '.$pair['access_token']]);

        $response->assertStatus(201);

        $hold = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Hold::query()->findOrFail($response->json('id')),
        );

        expect($hold->customer_id)->not->toBeNull();
    });

    it('stays null for anonymous guests', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);

        $response = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201);

        $hold = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Hold::query()->findOrFail($response->json('id')),
        );

        expect($hold->customer_id)->toBeNull();
    });

    it('rejects a body-supplied customer_id, deriving it from the bearer token instead', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);
        $pair = holdCustomerBearer($tenant->id, $host);

        $response = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'customer_id' => (string) Str::uuid7(),
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ], ['Authorization' => 'Bearer '.$pair['access_token']]);

        $response->assertStatus(201);

        $hold = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Hold::query()->findOrFail($response->json('id')),
        );

        expect($hold->customer_id)->not->toBe($response->json('customer_id') ?? 'bogus');
    });

    it('returns event_not_found for an unknown event', function (): void {
        ['host' => $host] = holdTenant();

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => (string) Str::uuid7(),
            'items' => [['ticket_type_id' => (string) Str::uuid7(), 'quantity' => 1]],
        ])
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'event_not_found');
    });

    it('returns event_not_found for a draft (unpublished) event', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id, ['status' => EventStatus::Draft]);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'event_not_found');
    });

    it('returns ticket_type_not_in_event when the ticket type belongs to a different event', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $otherEvent = holdEvent($tenant->id);
        $foreignTicketType = holdTicketType($tenant->id, $otherEvent->id, 10);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $foreignTicketType->id, 'quantity' => 1]],
        ])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'ticket_type_not_in_event');
    });

    it('returns sales_window_closed outside the sales window', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10, [
            'sales_start' => now()->addDay(),
            'sales_end' => now()->addDays(2),
        ]);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'sales_window_closed');
    });

    it('returns insufficient_inventory with the failing ticket_type_id in the errors extension', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 1);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'insufficient_inventory')
            ->assertJsonPath('errors.ticket_type_id.0', $ticketType->id);
    });

    it('returns request.validation_failed for a malformed body', function (): void {
        ['host' => $host] = holdTenant();

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => 'not-a-uuid',
            'items' => [],
        ])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('returns request.validation_failed for a zero or negative quantity', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 0]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('creates a seated hold, flipping the selected seats to held', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        ['ticketType' => $ticketType, 'seatIds' => $seatIds] = holdSeatedTicketType($tenant->id, $event->id, 2);

        $response = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            'seat_ids' => $seatIds,
        ]);

        $response->assertStatus(201)->assertConformsToOpenApi();
        expect($response->json('seat_ids'))->toEqualCanonicalizing($seatIds);

        $seats = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => EventSeat::query()->whereIn('id', $seatIds)->get(),
        );

        foreach ($seats as $seat) {
            expect($seat->status)->toBe(EventSeatStatus::Held);
        }
    });

    it('creates a mixed GA-plus-seated hold', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $gaTicketType = holdTicketType($tenant->id, $event->id, 10);
        ['ticketType' => $seatedTicketType, 'seatIds' => $seatIds] = holdSeatedTicketType($tenant->id, $event->id, 1);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [
                ['ticket_type_id' => $gaTicketType->id, 'quantity' => 3],
                ['ticket_type_id' => $seatedTicketType->id, 'quantity' => 1],
            ],
            'seat_ids' => $seatIds,
        ])->assertStatus(201)->assertJsonPath('seat_ids', $seatIds);
    });

    it('returns seat_selection_invalid when a requires_seat item has no matching seat_ids', function (string $seatIdsKey, array $seatIds): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        ['ticketType' => $ticketType] = holdSeatedTicketType($tenant->id, $event->id, 2);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            'seat_ids' => $seatIds,
        ])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'seat_selection_invalid');
    })->with([
        'no seat_ids at all' => ['none', []],
        'fewer seat_ids than quantity' => ['one', [(string) Str::uuid7()]],
    ]);

    it('returns seat_selection_invalid when seat_ids are given for a GA-only request', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            'seat_ids' => [(string) Str::uuid7()],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'seat_selection_invalid');
    });

    it('returns seat_unavailable with the offending seat_ids when a seat is already held', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        ['ticketType' => $ticketType, 'seatIds' => $seatIds] = holdSeatedTicketType($tenant->id, $event->id, 2);

        app(TenantTransaction::class)->asTenant($tenant->id, function () use ($tenant, $event, $seatIds): void {
            $blocker = Hold::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);
            EventSeat::query()->whereKey($seatIds[0])->update(['status' => EventSeatStatus::Held->value, 'hold_id' => $blocker->id]);
        });

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            'seat_ids' => $seatIds,
        ])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'seat_unavailable')
            ->assertJsonPath('errors.seat_ids.0', $seatIds[0]);
    });
});

function holdPurchaseCounterQuantity(string $tenantId, string $customerId, string $ticketTypeId): ?int
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => PurchaseCounter::query()
            ->where('customer_id', $customerId)
            ->where('ticket_type_id', $ticketTypeId)
            ->value('quantity'),
    );
}

/*
 * Stage-10 plan, TDD sequencing Slice 3 (Feature, first), task breakdown
 * item 6: purchase-limit enforcement wired into CreateHold, ReleaseHold,
 * CommitHold, and the expiry sweeper.
 */
describe('POST /v1/storefront/holds purchase limits', function (): void {
    it('returns purchase_limit_exceeded with the offending ticket_type_id and limit when the hold exceeds max_per_customer', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10, ['max_per_customer' => 2]);
        $pair = holdCustomerBearer($tenant->id, $host);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 3]],
        ], ['Authorization' => 'Bearer '.$pair['access_token']])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'purchase_limit_exceeded')
            ->assertJsonPath('ticket_type_id', $ticketType->id)
            ->assertJsonPath('limit', 2);
    });

    it('returns customer_required for a limited ticket type without an authenticated customer', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10, ['max_per_customer' => 2]);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'customer_required');
    });

    it('allows a hold exactly at the limit and records the counted quantity', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10, ['max_per_customer' => 2]);
        $pair = holdCustomerBearer($tenant->id, $host);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ], ['Authorization' => 'Bearer '.$pair['access_token']])
            ->assertStatus(201)
            ->assertConformsToOpenApi();

        $customerId = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Customer::query()->where('tenant_id', $tenant->id)->where('email', 'buyer@example.com')->firstOrFail()->id,
        );

        expect(holdPurchaseCounterQuantity($tenant->id, $customerId, $ticketType->id))->toBe(2);
    });

    it('restores headroom exactly when the hold is released', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10, ['max_per_customer' => 2]);
        $pair = holdCustomerBearer($tenant->id, $host);
        $headers = ['Authorization' => 'Bearer '.$pair['access_token']];

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ], $headers)->assertStatus(201)->json();

        $this->deleteJson('http://'.$host.'/v1/storefront/holds/'.$created['id'], [], $headers)
            ->assertStatus(204);

        $customerId = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Customer::query()->where('tenant_id', $tenant->id)->where('email', 'buyer@example.com')->firstOrFail()->id,
        );

        expect(holdPurchaseCounterQuantity($tenant->id, $customerId, $ticketType->id))->toBe(0);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ], $headers)->assertStatus(201);
    });

    it('restores headroom exactly when the hold expires', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10, ['max_per_customer' => 2]);
        $pair = holdCustomerBearer($tenant->id, $host);
        $headers = ['Authorization' => 'Bearer '.$pair['access_token']];

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ], $headers)->assertStatus(201);

        $customerId = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Customer::query()->where('tenant_id', $tenant->id)->where('email', 'buyer@example.com')->firstOrFail()->id,
        );

        $this->travelTo(now()->addMinutes(11));

        app(ReleaseExpiredHolds::class)();

        expect(holdPurchaseCounterQuantity($tenant->id, $customerId, $ticketType->id))->toBe(0);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ], $headers)->assertStatus(201);
    });

    it('keeps a committed hold consuming the limit on the next attempt', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10, ['max_per_customer' => 2]);
        $pair = holdCustomerBearer($tenant->id, $host);
        $headers = ['Authorization' => 'Bearer '.$pair['access_token']];

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ], $headers)->assertStatus(201)->json();

        app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => app(CommitHold::class)(CommitHoldData::from(['holdId' => $created['id']])),
        );

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('code', 'purchase_limit_exceeded');
    });

    it('releases a hold created before the limit existed without decrementing the counter', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);
        $pair = holdCustomerBearer($tenant->id, $host);
        $headers = ['Authorization' => 'Bearer '.$pair['access_token']];

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ], $headers)->assertStatus(201)->json();

        $countedQuantity = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => DB::table('hold_items')->where('hold_id', $created['id'])->value('counted_quantity'),
        );

        expect($countedQuantity)->toBe(0);

        app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => TicketType::query()->whereKey($ticketType->id)->update(['max_per_customer' => 2]),
        );

        $this->deleteJson('http://'.$host.'/v1/storefront/holds/'.$created['id'], [], $headers)
            ->assertStatus(204);

        $customerId = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Customer::query()->where('tenant_id', $tenant->id)->where('email', 'buyer@example.com')->firstOrFail()->id,
        );

        expect(holdPurchaseCounterQuantity($tenant->id, $customerId, $ticketType->id))->toBeNull();
    });

    it('still decrements the recorded counted quantity on release after max_per_customer is cleared', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10, ['max_per_customer' => 2]);
        $pair = holdCustomerBearer($tenant->id, $host);
        $headers = ['Authorization' => 'Bearer '.$pair['access_token']];

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
        ], $headers)->assertStatus(201)->json();

        $customerId = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => Customer::query()->where('tenant_id', $tenant->id)->where('email', 'buyer@example.com')->firstOrFail()->id,
        );

        expect(holdPurchaseCounterQuantity($tenant->id, $customerId, $ticketType->id))->toBe(2);

        app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => TicketType::query()->whereKey($ticketType->id)->update(['max_per_customer' => null]),
        );

        $this->deleteJson('http://'.$host.'/v1/storefront/holds/'.$created['id'], [], $headers)
            ->assertStatus(204);

        expect(holdPurchaseCounterQuantity($tenant->id, $customerId, $ticketType->id))->toBe(0);
    });
});

describe('DELETE /v1/storefront/holds/{hold} releases seats', function (): void {
    it('returns a seated hold to available on release', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        ['ticketType' => $ticketType, 'seatIds' => $seatIds] = holdSeatedTicketType($tenant->id, $event->id, 2);

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            'seat_ids' => $seatIds,
        ])->json();

        $this->deleteJson('http://'.$host.'/v1/storefront/holds/'.$created['id'])->assertStatus(204);

        $seats = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => EventSeat::query()->whereIn('id', $seatIds)->get(),
        );

        foreach ($seats as $seat) {
            expect($seat->status)->toBe(EventSeatStatus::Available)
                ->and($seat->hold_id)->toBeNull();
        }
    });

    it('returns a seated hold to available on expiry', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        ['ticketType' => $ticketType, 'seatIds' => $seatIds] = holdSeatedTicketType($tenant->id, $event->id, 2);

        $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 2]],
            'seat_ids' => $seatIds,
        ])->assertStatus(201);

        $this->travelTo(now()->addMinutes(11));

        app(ReleaseExpiredHolds::class)();

        $seats = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => EventSeat::query()->whereIn('id', $seatIds)->get(),
        );

        foreach ($seats as $seat) {
            expect($seat->status)->toBe(EventSeatStatus::Available)
                ->and($seat->hold_id)->toBeNull();
        }
    });
});

describe('GET /v1/storefront/holds/{hold}', function (): void {
    it('returns the hold', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])->json();

        $this->getJson('http://'.$host.'/v1/storefront/holds/'.$created['id'])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $created['id'])
            ->assertJsonPath('event_id', $event->id)
            ->assertJsonPath('status', 'active');
    });

    it('returns hold_not_found for an unknown hold', function (): void {
        ['host' => $host] = holdTenant();

        $this->getJson('http://'.$host.'/v1/storefront/holds/'.Str::uuid7())
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'hold_not_found');
    });

    it('returns hold_not_found for a cross-tenant hold', function (): void {
        ['tenant' => $tenantA, 'host' => $hostA] = holdTenant();
        ['tenant' => $tenantB, 'host' => $hostB] = holdTenant();

        $event = holdEvent($tenantA->id);
        $ticketType = holdTicketType($tenantA->id, $event->id, 10);

        $created = $this->postJson('http://'.$hostA.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])->json();

        $this->getJson('http://'.$hostB.'/v1/storefront/holds/'.$created['id'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'hold_not_found');
    });
});

describe('DELETE /v1/storefront/holds/{hold}', function (): void {
    it('releases an active hold and returns 204, freeing its held inventory', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 3]],
        ])->json();

        $this->deleteJson('http://'.$host.'/v1/storefront/holds/'.$created['id'])
            ->assertStatus(204)
            ->assertConformsToOpenApi();

        $this->getJson('http://'.$host.'/v1/storefront/holds/'.$created['id'])
            ->assertJsonPath('status', 'released');

        $inventory = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketType->id)->first(),
        );

        expect($inventory->held)->toBe(0);
    });

    it('is idempotent: releasing an already-released hold returns 204 again', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])->json();

        $this->deleteJson('http://'.$host.'/v1/storefront/holds/'.$created['id'])->assertStatus(204);
        $this->deleteJson('http://'.$host.'/v1/storefront/holds/'.$created['id'])->assertStatus(204);
    });

    it('returns hold_not_releasable 409 for a committed hold', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])->json();

        app(TenantTransaction::class)->asTenant($tenant->id, function () use ($created): void {
            DB::table('holds')->where('id', $created['id'])->update(['status' => 'committed']);
        });

        $this->deleteJson('http://'.$host.'/v1/storefront/holds/'.$created['id'])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'hold_not_releasable');
    });

    it('returns hold_not_found for an unknown hold', function (): void {
        ['host' => $host] = holdTenant();

        $this->deleteJson('http://'.$host.'/v1/storefront/holds/'.Str::uuid7())
            ->assertStatus(404)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'hold_not_found');
    });

    it('returns hold_not_found for a cross-tenant hold', function (): void {
        ['tenant' => $tenantA, 'host' => $hostA] = holdTenant();
        ['host' => $hostB] = holdTenant();

        $event = holdEvent($tenantA->id);
        $ticketType = holdTicketType($tenantA->id, $event->id, 10);

        $created = $this->postJson('http://'.$hostA.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])->json();

        $this->deleteJson('http://'.$hostB.'/v1/storefront/holds/'.$created['id'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'hold_not_found');
    });

    it('is a no-op 204 for an already-expired hold, availability recovers exactly', function (): void {
        ['tenant' => $tenant, 'host' => $host] = holdTenant();
        $event = holdEvent($tenant->id);
        $ticketType = holdTicketType($tenant->id, $event->id, 10);

        $created = $this->postJson('http://'.$host.'/v1/storefront/holds', [
            'event_id' => $event->id,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 4]],
        ])->json();

        $this->travelTo(now()->addMinutes(11));

        app(ReleaseExpiredHolds::class)();

        $this->deleteJson('http://'.$host.'/v1/storefront/holds/'.$created['id'])
            ->assertStatus(204);

        $inventory = app(TenantTransaction::class)->asTenant(
            $tenant->id,
            fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketType->id)->first(),
        );

        expect($inventory->sold + $inventory->held)->toBe(0)
            ->and($inventory->quantity - $inventory->sold - $inventory->held)->toBe(10);
    });
});
