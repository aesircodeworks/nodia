<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Models\Customer;
use App\Inventory\Actions\ReleaseExpiredHolds;
use App\Inventory\Models\Hold;
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
            DB::table('holds')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
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
