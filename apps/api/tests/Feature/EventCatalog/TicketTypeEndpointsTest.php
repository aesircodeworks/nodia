<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Identity\Capability;
use App\Inventory\Models\TicketTypeInventory;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-05a plan, task breakdown item 8 (TDD slice 3 completion): ticket
 * type admin CRUD under the tenancy.admin group, mirroring
 * tests/Feature/EventCatalog/EventEndpointsTest.php's own structure.
 * Every tenant here defaults to settlement_currency USD (TenantFactory),
 * matching this file's own USD-priced payloads unless a scenario is
 * specifically about a currency mismatch.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsView, Capability::EventsManage]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeTicketTypeEvent(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeTicketTypeRow(string $tenantId, string $eventId, array $attributes = []): TicketType
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $eventId, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ticketTypePayload(array $overrides = []): array
{
    return [
        'name' => 'General Admission',
        'price' => ['amount' => 5000, 'currency' => 'USD'],
        'sales_start' => null,
        'sales_end' => null,
        ...$overrides,
    ];
}

describe('POST /v1/events/{event}/ticket-types', function () {
    it('creates a ticket type with the {amount, currency} wire shape both ways', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $response = $this->postJson(
            "/v1/events/{$event->id}/ticket-types",
            ticketTypePayload(['price' => ['amount' => 12345, 'currency' => 'USD']]),
        );

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'tenant_id' => $this->tenantId,
                'event_id' => $event->id,
                'name' => 'General Admission',
                'price' => ['amount' => 12345, 'currency' => 'USD'],
                'sales_start' => null,
                'sales_end' => null,
                'requires_seat' => false,
            ]);

        $body = $response->json();

        expect(Str::isUuid($body['id']))->toBeTrue()
            ->and(array_keys($body))->toBe([
                'id', 'tenant_id', 'event_id', 'name', 'price', 'sales_start',
                'sales_end', 'requires_seat', 'created_at', 'updated_at',
            ]);
    });

    it('returns catalog.currency_mismatch when the price currency differs from the tenant settlement currency', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $this->postJson(
            "/v1/events/{$event->id}/ticket-types",
            ticketTypePayload(['price' => ['amount' => 5000, 'currency' => 'EUR']]),
        )
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'catalog.currency_mismatch');
    });

    it('rejects a negative price', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $response = $this->postJson(
            "/v1/events/{$event->id}/ticket-types",
            ticketTypePayload(['price' => ['amount' => -100, 'currency' => 'USD']]),
        );

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('price.amount');
    });

    it('rejects sales_end before sales_start', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $response = $this->postJson("/v1/events/{$event->id}/ticket-types", ticketTypePayload([
            'sales_start' => '2026-08-01T00:00:00Z',
            'sales_end' => '2026-07-01T00:00:00Z',
        ]));

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('sales_end');
    });

    it('accepts a sales window with only one side set', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $this->postJson("/v1/events/{$event->id}/ticket-types", ticketTypePayload([
            'sales_start' => '2026-08-01T00:00:00Z',
        ]))
            ->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJsonPath('sales_start', '2026-08-01T00:00:00Z')
            ->assertJsonPath('sales_end', null);
    });

    it('returns catalog.event_immutable when the parent event is canceled', function () {
        $event = makeTicketTypeEvent($this->tenantId, ['status' => EventStatus::Canceled]);

        $this->postJson("/v1/events/{$event->id}/ticket-types", ticketTypePayload())
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'catalog.event_immutable');
    });

    it('returns a request.not_found problem for a foreign tenant\'s event', function () {
        $event = makeTicketTypeEvent($this->otherTenantId);

        $this->postJson("/v1/events/{$event->id}/ticket-types", ticketTypePayload())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $this->withoutToken()
            ->postJson("/v1/events/{$event->id}/ticket-types", ticketTypePayload(), ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.manage with missing_capability', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView)])
            ->postJson("/v1/events/{$event->id}/ticket-types", ticketTypePayload())
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/events/{event}/ticket-types', function () {
    it('returns the standard paginator envelope of TicketTypeData', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        makeTicketTypeRow($this->tenantId, $event->id, ['name' => 'VIP']);

        $response = $this->getJson("/v1/events/{$event->id}/ticket-types");

        $response->assertOk()->assertConformsToOpenApi();

        $body = $response->json();

        expect(array_keys($body))->toContain('data', 'links', 'meta')
            ->and(collect($body['data'])->pluck('name')->all())->toContain('VIP');
    });

    it('returns a request.not_found problem for a foreign tenant\'s event', function () {
        $event = makeTicketTypeEvent($this->otherTenantId);

        $this->getJson("/v1/events/{$event->id}/ticket-types")
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $this->withoutToken()
            ->getJson("/v1/events/{$event->id}/ticket-types", ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.view with missing_capability', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView)])
            ->getJson("/v1/events/{$event->id}/ticket-types")
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/ticket-types/{ticket_type}', function () {
    it('returns the ticket type', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id, ['name' => 'Readable Ticket']);

        $this->getJson("/v1/ticket-types/{$ticketType->id}")
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $ticketType->id)
            ->assertJsonPath('name', 'Readable Ticket');
    });

    it('returns a request.not_found problem for a foreign tenant\'s ticket type', function () {
        $event = makeTicketTypeEvent($this->otherTenantId);
        $ticketType = makeTicketTypeRow($this->otherTenantId, $event->id);

        $this->getJson("/v1/ticket-types/{$ticketType->id}")
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for an unknown ticket type id', function () {
        $this->getJson('/v1/ticket-types/'.Str::uuid7())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for a malformed ticket type id that matches no route', function () {
        $this->getJson('/v1/ticket-types/not-a-uuid')
            ->assertNotFound()
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', 'request.not_found');
    });
});

describe('PATCH /v1/ticket-types/{ticket_type}', function () {
    it('updates the given fields', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id, ['name' => 'Before']);

        $this->patchJson("/v1/ticket-types/{$ticketType->id}", ['name' => 'After'])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['name' => 'After']);
    });

    it('leaves fields absent from the payload untouched', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id, [
            'name' => 'Untouched',
            'price' => Money::of(5000, 'USD'),
        ]);

        $this->patchJson("/v1/ticket-types/{$ticketType->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['name' => 'Renamed', 'price' => ['amount' => 5000, 'currency' => 'USD']]);
    });

    it('updates the price', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id);

        $this->patchJson("/v1/ticket-types/{$ticketType->id}", [
            'price' => ['amount' => 9999, 'currency' => 'USD'],
        ])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['price' => ['amount' => 9999, 'currency' => 'USD']]);
    });

    it('returns catalog.currency_mismatch when the updated price currency differs from the tenant settlement currency', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id);

        $this->patchJson("/v1/ticket-types/{$ticketType->id}", [
            'price' => ['amount' => 5000, 'currency' => 'EUR'],
        ])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'catalog.currency_mismatch');
    });

    it('rejects updating only one side of the sales window', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id);

        $response = $this->patchJson("/v1/ticket-types/{$ticketType->id}", [
            'sales_start' => '2026-08-01T00:00:00Z',
        ]);

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('sales_end');
    });

    it('updates the sales window pair together', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id);

        $this->patchJson("/v1/ticket-types/{$ticketType->id}", [
            'sales_start' => '2026-08-01T00:00:00Z',
            'sales_end' => '2026-09-01T00:00:00Z',
        ])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['sales_start' => '2026-08-01T00:00:00Z', 'sales_end' => '2026-09-01T00:00:00Z']);
    });

    it('returns catalog.event_immutable when the parent event is canceled', function () {
        $event = makeTicketTypeEvent($this->tenantId, ['status' => EventStatus::Canceled]);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id);

        $this->patchJson("/v1/ticket-types/{$ticketType->id}", ['name' => 'Renamed'])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'catalog.event_immutable');
    });

    it('allows a PATCH when the parent event is published', function () {
        $event = makeTicketTypeEvent($this->tenantId, ['status' => EventStatus::Published]);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id);

        $this->patchJson("/v1/ticket-types/{$ticketType->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('name', 'Renamed');
    });

    it('returns a request.not_found problem for a foreign tenant\'s ticket type', function () {
        $event = makeTicketTypeEvent($this->otherTenantId);
        $ticketType = makeTicketTypeRow($this->otherTenantId, $event->id);

        $this->patchJson("/v1/ticket-types/{$ticketType->id}", ['name' => 'Renamed'])
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id);

        $this->withoutToken()
            ->patchJson("/v1/ticket-types/{$ticketType->id}", ['name' => 'Renamed'], ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.manage with missing_capability', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id);

        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView)])
            ->patchJson("/v1/ticket-types/{$ticketType->id}", ['name' => 'Renamed'])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

/*
 * Stage-06 plan, task breakdown item 3: the additive `quantity` field on
 * the create/update Data objects, delegated to
 * App\Inventory\Actions\SetTicketTypeQuantity rather than any column on
 * ticket_types itself (task-06 plan, Risks: "Quantity input ownership").
 * TicketTypeData's own response shape is untouched by this task (no
 * `quantity` key on the wire yet; the admin inventory read arrives in
 * task 7), so these tests assert against the `ticket_type_inventory`
 * table directly, mirroring TicketTypeInventoryIsolationTest's own
 * ownership of that table's assertions.
 */
describe('ticket type quantity (stage-06 task 3)', function () {
    it('creates the ticket_type_inventory counter row for a GA ticket type given a quantity', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $response = $this->postJson(
            "/v1/events/{$event->id}/ticket-types",
            ticketTypePayload(['quantity' => 250]),
        );

        $response->assertCreated()->assertConformsToOpenApi();

        $ticketTypeId = $response->json('id');

        $inventory = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
        );

        expect($inventory)->not->toBeNull()
            ->and($inventory->quantity)->toBe(250)
            ->and($inventory->held)->toBe(0)
            ->and($inventory->sold)->toBe(0);
    });

    it('creates a zero-quantity counter row for a GA ticket type given no quantity', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $response = $this->postJson("/v1/events/{$event->id}/ticket-types", ticketTypePayload());

        $response->assertCreated()->assertConformsToOpenApi();

        $ticketTypeId = $response->json('id');

        $inventory = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
        );

        expect($inventory)->not->toBeNull()->and($inventory->quantity)->toBe(0);
    });

    it('rejects a quantity on a requires_seat ticket type with request.validation_failed', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $response = $this->postJson(
            "/v1/events/{$event->id}/ticket-types",
            ticketTypePayload(['requires_seat' => true, 'quantity' => 100]),
        );

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('quantity');
    });

    it('does not create a counter row for a requires_seat ticket type', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $response = $this->postJson(
            "/v1/events/{$event->id}/ticket-types",
            ticketTypePayload(['requires_seat' => true]),
        );

        $response->assertCreated()->assertConformsToOpenApi();

        $ticketTypeId = $response->json('id');

        $inventory = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
        );

        expect($inventory)->toBeNull();
    });

    it('adjusts the counter row to the given quantity on update', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $ticketTypeId = $this->postJson(
            "/v1/events/{$event->id}/ticket-types",
            ticketTypePayload(['quantity' => 100]),
        )->json('id');

        $this->patchJson("/v1/ticket-types/{$ticketTypeId}", ['quantity' => 150])
            ->assertOk()
            ->assertConformsToOpenApi();

        $inventory = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->first(),
        );

        expect($inventory->quantity)->toBe(150);
    });

    it('returns insufficient_inventory when the updated quantity would undercut sold plus held', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $ticketTypeId = $this->postJson(
            "/v1/events/{$event->id}/ticket-types",
            ticketTypePayload(['quantity' => 10]),
        )->json('id');

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($ticketTypeId): void {
            TicketTypeInventory::query()->where('ticket_type_id', $ticketTypeId)->update(['sold' => 8]);
        });

        $this->patchJson("/v1/ticket-types/{$ticketTypeId}", ['quantity' => 5])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'insufficient_inventory');
    });

    it('rejects a quantity update on a requires_seat ticket type', function () {
        $event = makeTicketTypeEvent($this->tenantId);
        $ticketType = makeTicketTypeRow($this->tenantId, $event->id, ['requires_seat' => true]);

        $response = $this->patchJson("/v1/ticket-types/{$ticketType->id}", ['quantity' => 50]);

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('quantity');
    });

    it('rejects a negative quantity', function () {
        $event = makeTicketTypeEvent($this->tenantId);

        $response = $this->postJson(
            "/v1/events/{$event->id}/ticket-types",
            ticketTypePayload(['quantity' => -1]),
        );

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('quantity');
    });
});
