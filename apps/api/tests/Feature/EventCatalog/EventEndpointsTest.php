<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\EventCatalog\Models\Venue;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\TenantStaff;

/*
 * Stage-05a plan, task breakdown item 5 (TDD slice 2): event admin CRUD
 * under the tenancy.admin group, mirroring
 * tests/Feature/EventCatalog/VenueEndpointsTest.php's own structure.
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
function makeEventVenue(string $tenantId, array $attributes = []): Venue
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Venue::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeEventRow(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeEventTicketType(string $tenantId, string $eventId, array $attributes = []): TicketType
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
function gaEventPayload(string $venueId, array $overrides = []): array
{
    return [
        'name' => ['en' => 'Grand Gala'],
        'description' => ['en' => 'A gala event.'],
        'venue_id' => $venueId,
        'is_virtual' => false,
        'virtual_event_url' => null,
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'America/Chicago',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function virtualEventPayload(array $overrides = []): array
{
    return [
        'name' => ['en' => 'Virtual Summit'],
        'description' => ['en' => 'Online only.'],
        'venue_id' => null,
        'is_virtual' => true,
        'virtual_event_url' => 'https://example.test/stream',
        'start_at' => '2026-08-01T18:00:00Z',
        'end_at' => '2026-08-01T21:00:00Z',
        'timezone' => 'UTC',
        ...$overrides,
    ];
}

describe('POST /v1/events', function () {
    it('creates a GA event with a venue and returns the EventData wire shape', function () {
        $venue = makeEventVenue($this->tenantId);

        $response = $this->postJson('/v1/events', gaEventPayload($venue->id));

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'tenant_id' => $this->tenantId,
                'venue_id' => $venue->id,
                'status' => 'draft',
                'name' => ['en' => 'Grand Gala'],
                'description' => ['en' => 'A gala event.'],
                'timezone' => 'America/Chicago',
                'is_virtual' => false,
                'virtual_event_url' => null,
            ]);

        $body = $response->json();

        expect(Str::isUuid($body['id']))->toBeTrue()
            ->and($body['start_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($body['end_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($body['async_payment_policy'])->toBe(['slow_methods_enabled' => true, 'low_inventory_cutoff' => null])
            ->and(array_keys($body))->toBe([
                'id', 'tenant_id', 'venue_id', 'status', 'name', 'description', 'start_at', 'end_at',
                'timezone', 'is_virtual', 'virtual_event_url', 'async_payment_policy', 'created_at', 'updated_at',
            ]);
    });

    it('creates a virtual event with a url and no venue', function () {
        $response = $this->postJson('/v1/events', virtualEventPayload());

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'venue_id' => null,
                'is_virtual' => true,
                'virtual_event_url' => 'https://example.test/stream',
            ]);
    });

    it('rejects an invalid is_virtual/venue_id/virtual_event_url combination naming the offending fields', function (array $overrides) {
        $venue = makeEventVenue($this->tenantId);

        $response = $this->postJson('/v1/events', gaEventPayload($venue->id, $overrides));

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKeys(['is_virtual', 'venue_id', 'virtual_event_url']);
    })->with([
        'virtual event that also carries a venue_id' => [['is_virtual' => true, 'virtual_event_url' => 'https://example.test/stream']],
        'virtual event missing its virtual_event_url' => [['is_virtual' => true, 'virtual_event_url' => null]],
        'physical event missing its venue_id' => [['venue_id' => null]],
        'physical event that also carries a virtual_event_url' => [['virtual_event_url' => 'https://example.test/stream']],
    ]);

    it('rejects a venue_id belonging to another tenant', function () {
        $foreignVenue = makeEventVenue($this->otherTenantId);

        $response = $this->postJson('/v1/events', gaEventPayload($foreignVenue->id));

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('venue_id');
    });

    it('rejects an unknown venue_id', function () {
        $response = $this->postJson('/v1/events', gaEventPayload(Str::uuid7()->toString()));

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('venue_id');
    });

    it('requires the tenant default locale in a translatable payload', function () {
        $venue = makeEventVenue($this->tenantId);

        $response = $this->postJson('/v1/events', gaEventPayload($venue->id, [
            'name' => ['fr' => 'Gala Grandiose'],
        ]));

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('name');
    });

    it('rejects a locale outside the tenant supported_locales', function () {
        $tenantId = app(TenantTransaction::class)->asPlatform(
            fn () => Tenant::factory()->create(['default_locale' => 'en', 'supported_locales' => ['en', 'fr']])->id,
        );
        $token = TenantStaff::token($tenantId, [Capability::EventsView, Capability::EventsManage]);
        $venue = makeEventVenue($tenantId);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Tenant-Id' => $tenantId])
            ->postJson('/v1/events', gaEventPayload($venue->id, [
                'name' => ['en' => 'Grand Gala', 'de' => 'Grosse Gala'],
            ]));

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('name.de');

        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
        });
        app(TenantTransaction::class)->asPlatform(function () use ($tenantId): void {
            DB::table('roles')->where('tenant_id', $tenantId)->delete();
            Tenant::query()->whereKey($tenantId)->delete();
        });
    });

    it('rejects an invalid IANA timezone', function () {
        $venue = makeEventVenue($this->tenantId);

        $this->postJson('/v1/events', gaEventPayload($venue->id, ['timezone' => 'Not/ARealZone']))
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('rejects end_at at or before start_at', function (string $endAt) {
        $venue = makeEventVenue($this->tenantId);

        $response = $this->postJson('/v1/events', gaEventPayload($venue->id, [
            'start_at' => '2026-08-01T18:00:00Z',
            'end_at' => $endAt,
        ]));

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('end_at');
    })->with([
        'equal to start_at' => ['2026-08-01T18:00:00Z'],
        'before start_at' => ['2026-08-01T17:00:00Z'],
    ]);

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->postJson('/v1/events', virtualEventPayload(), ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.manage with missing_capability', function () {
        $venue = makeEventVenue($this->tenantId);

        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView)])
            ->postJson('/v1/events', gaEventPayload($venue->id))
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/events', function () {
    it('returns the standard paginator envelope of EventData', function () {
        makeEventRow($this->tenantId, ['name' => ['en' => 'Envelope Event']]);

        $response = $this->getJson('/v1/events');

        $response->assertOk()->assertConformsToOpenApi();

        $body = $response->json();

        expect(array_keys($body))->toContain('data', 'links', 'meta')
            ->and(collect($body['data'])->pluck('name.en')->all())->toContain('Envelope Event');
    });

    it('never returns a foreign tenant\'s event', function () {
        makeEventRow($this->tenantId, ['name' => ['en' => 'Own Event']]);
        makeEventRow($this->otherTenantId, ['name' => ['en' => 'Foreign Event']]);

        $names = collect($this->getJson('/v1/events')->assertOk()->json('data'))->pluck('name.en');

        expect($names)->toContain('Own Event')
            ->and($names)->not->toContain('Foreign Event');
    });

    it('filters by filter[status]', function () {
        makeEventRow($this->tenantId, ['name' => ['en' => 'Draft Event'], 'status' => EventStatus::Draft]);
        makeEventRow($this->tenantId, ['name' => ['en' => 'Canceled Event'], 'status' => EventStatus::Canceled]);

        $this->getJson('/v1/events?filter[status]=canceled')
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name.en', 'Canceled Event');
    });

    it('filters by filter[venue_id]', function () {
        $venue = makeEventVenue($this->tenantId);
        makeEventRow($this->tenantId, ['name' => ['en' => 'Unrelated Event']]);
        makeEventRow($this->tenantId, [
            'name' => ['en' => 'Matching Venue'],
            'is_virtual' => false,
            'venue_id' => $venue->id,
            'virtual_event_url' => null,
        ]);

        $this->getJson('/v1/events?filter[venue_id]='.$venue->id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name.en', 'Matching Venue');
    });

    it('filters by filter[is_virtual]', function () {
        makeEventRow($this->tenantId, ['name' => ['en' => 'Virtual Event'], 'is_virtual' => true, 'venue_id' => null, 'virtual_event_url' => 'https://example.test/a']);
        $venue = makeEventVenue($this->tenantId);
        makeEventRow($this->tenantId, ['name' => ['en' => 'Physical Event'], 'is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null]);

        $this->getJson('/v1/events?filter[is_virtual]=true')
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name.en', 'Virtual Event');
    });

    it('sorts by the allowed sorts in both directions', function (string $sort, array $expected) {
        makeEventRow($this->tenantId, ['name' => ['en' => 'B Event'], 'start_at' => '2026-09-02T00:00:00Z', 'end_at' => '2026-09-02T03:00:00Z', 'created_at' => '2026-01-02T00:00:00Z']);
        makeEventRow($this->tenantId, ['name' => ['en' => 'A Event'], 'start_at' => '2026-09-01T00:00:00Z', 'end_at' => '2026-09-01T03:00:00Z', 'created_at' => '2026-01-03T00:00:00Z']);

        $names = collect($this->getJson('/v1/events?sort='.$sort)->assertOk()->json('data'))
            ->pluck('name.en')
            ->all();

        expect($names)->toBe($expected);
    })->with([
        'start_at' => ['start_at', ['A Event', 'B Event']],
        '-start_at' => ['-start_at', ['B Event', 'A Event']],
        'created_at' => ['created_at', ['B Event', 'A Event']],
        '-created_at' => ['-created_at', ['A Event', 'B Event']],
    ]);

    it('embeds the venue when include=venue is requested', function () {
        $venue = makeEventVenue($this->tenantId, ['name' => 'Included Venue']);
        makeEventRow($this->tenantId, ['name' => ['en' => 'Venued Event'], 'is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null]);

        $response = $this->getJson('/v1/events?include=venue')->assertOk()->assertConformsToOpenApi();

        expect($response->json('data.0.venue.id'))->toBe($venue->id)
            ->and($response->json('data.0.venue.name'))->toBe('Included Venue');
    });

    it('omits venue from the wire shape when include is not requested', function () {
        makeEventRow($this->tenantId);

        $response = $this->getJson('/v1/events')->assertOk();

        expect($response->json('data.0'))->not->toHaveKey('venue');
    });

    it('embeds ticket_types when include=ticket_types is requested', function () {
        $event = makeEventRow($this->tenantId, ['name' => ['en' => 'Ticketed Event']]);
        makeEventTicketType($this->tenantId, $event->id, ['name' => 'General Admission']);

        $response = $this->getJson('/v1/events?include=ticket_types')->assertOk()->assertConformsToOpenApi();

        expect($response->json('data.0.ticket_types'))->toHaveCount(1)
            ->and($response->json('data.0.ticket_types.0.name'))->toBe('General Admission')
            ->and($response->json('data.0.ticket_types.0.price'))->toHaveKeys(['amount', 'currency']);
    });

    it('omits ticket_types from the wire shape when include is not requested', function () {
        makeEventRow($this->tenantId);

        $response = $this->getJson('/v1/events')->assertOk();

        expect($response->json('data.0'))->not->toHaveKey('ticket_types');
    });

    it('rejects an unknown filter with an invalid_query_parameter problem', function () {
        $this->getJson('/v1/events?filter[unknown]=x')
            ->assertBadRequest()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects an unknown sort with an invalid_query_parameter problem', function () {
        $this->getJson('/v1/events?sort=venue_id')
            ->assertBadRequest()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->getJson('/v1/events', ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.view with missing_capability', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::OrdersView)])
            ->getJson('/v1/events')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('GET /v1/events/{event}', function () {
    it('returns the event', function () {
        $event = makeEventRow($this->tenantId, ['name' => ['en' => 'Readable Event']]);

        $this->getJson('/v1/events/'.$event->id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $event->id)
            ->assertJsonPath('name.en', 'Readable Event');
    });

    it('embeds the venue when include=venue is requested', function () {
        $venue = makeEventVenue($this->tenantId, ['name' => 'Detail Venue']);
        $event = makeEventRow($this->tenantId, ['is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null]);

        $response = $this->getJson('/v1/events/'.$event->id.'?include=venue')->assertOk()->assertConformsToOpenApi();

        expect($response->json('venue.id'))->toBe($venue->id);
    });

    it('embeds ticket_types when include=ticket_types is requested', function () {
        $event = makeEventRow($this->tenantId);
        makeEventTicketType($this->tenantId, $event->id, ['name' => 'VIP']);

        $response = $this->getJson('/v1/events/'.$event->id.'?include=ticket_types')->assertOk()->assertConformsToOpenApi();

        expect($response->json('ticket_types'))->toHaveCount(1)
            ->and($response->json('ticket_types.0.name'))->toBe('VIP')
            ->and($response->json('ticket_types.0.price'))->toHaveKeys(['amount', 'currency']);
    });

    it('returns a request.not_found problem for a foreign tenant\'s event', function () {
        $event = makeEventRow($this->otherTenantId);

        $this->getJson('/v1/events/'.$event->id)
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for an unknown event id', function () {
        $this->getJson('/v1/events/'.Str::uuid7())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for a malformed event id that matches no route', function () {
        $this->getJson('/v1/events/not-a-uuid')
            ->assertNotFound()
            ->assertMatchesProblemSchema()
            ->assertJsonPath('code', 'request.not_found');
    });
});

describe('PATCH /v1/events/{event}', function () {
    it('updates the given fields', function () {
        $event = makeEventRow($this->tenantId, ['name' => ['en' => 'Before'], 'timezone' => 'UTC']);

        $this->patchJson('/v1/events/'.$event->id, ['name' => ['en' => 'After'], 'timezone' => 'America/Chicago'])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['name' => ['en' => 'After'], 'timezone' => 'America/Chicago']);
    });

    it('leaves fields absent from the payload untouched', function () {
        $event = makeEventRow($this->tenantId, ['name' => ['en' => 'Untouched'], 'timezone' => 'UTC']);

        $this->patchJson('/v1/events/'.$event->id, ['timezone' => 'America/Chicago'])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['name' => ['en' => 'Untouched'], 'timezone' => 'America/Chicago']);
    });

    it('updates the venue/virtual trio together', function () {
        $event = makeEventRow($this->tenantId);
        $venue = makeEventVenue($this->tenantId);

        $this->patchJson('/v1/events/'.$event->id, [
            'is_virtual' => false,
            'venue_id' => $venue->id,
            'virtual_event_url' => null,
        ])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['is_virtual' => false, 'venue_id' => $venue->id, 'virtual_event_url' => null]);
    });

    it('rejects updating only part of the venue/virtual trio', function () {
        $event = makeEventRow($this->tenantId);
        $venue = makeEventVenue($this->tenantId);

        $response = $this->patchJson('/v1/events/'.$event->id, ['venue_id' => $venue->id]);

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKeys(['is_virtual', 'virtual_event_url']);
    });

    it('updates the start_at/end_at pair together', function () {
        $event = makeEventRow($this->tenantId, ['start_at' => '2026-09-01T00:00:00Z', 'end_at' => '2026-09-01T03:00:00Z']);

        $this->patchJson('/v1/events/'.$event->id, [
            'start_at' => '2026-10-01T00:00:00Z',
            'end_at' => '2026-10-01T03:00:00Z',
        ])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJson(['start_at' => '2026-10-01T00:00:00Z', 'end_at' => '2026-10-01T03:00:00Z']);
    });

    it('rejects updating only part of the start_at/end_at pair', function () {
        $event = makeEventRow($this->tenantId);

        $response = $this->patchJson('/v1/events/'.$event->id, ['end_at' => '2026-12-01T00:00:00Z']);

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('start_at');
    });

    it('returns catalog.event_immutable for a PATCH on a canceled event', function () {
        $event = makeEventRow($this->tenantId, ['status' => EventStatus::Canceled]);

        $this->patchJson('/v1/events/'.$event->id, ['timezone' => 'America/Chicago'])
            ->assertStatus(409)
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'catalog.event_immutable');
    });

    it('allows a PATCH on a published event', function () {
        $event = makeEventRow($this->tenantId, ['status' => EventStatus::Published]);

        $this->patchJson('/v1/events/'.$event->id, ['timezone' => 'America/Chicago'])
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('status', 'published');
    });

    it('rejects updating to a venue_id belonging to another tenant', function () {
        $event = makeEventRow($this->tenantId);
        $foreignVenue = makeEventVenue($this->otherTenantId);

        $response = $this->patchJson('/v1/events/'.$event->id, [
            'is_virtual' => false,
            'venue_id' => $foreignVenue->id,
            'virtual_event_url' => null,
        ]);

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('venue_id');
    });

    it('returns a request.not_found problem for a foreign tenant\'s event', function () {
        $event = makeEventRow($this->otherTenantId);

        $this->patchJson('/v1/events/'.$event->id, ['timezone' => 'UTC'])
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $event = makeEventRow($this->tenantId);

        $this->withoutToken()
            ->patchJson('/v1/events/'.$event->id, ['timezone' => 'UTC'], ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.manage with missing_capability', function () {
        $event = makeEventRow($this->tenantId);

        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView)])
            ->patchJson('/v1/events/'.$event->id, ['timezone' => 'UTC'])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});
