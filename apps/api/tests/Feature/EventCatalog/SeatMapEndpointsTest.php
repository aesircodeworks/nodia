<?php

use App\EventCatalog\Models\Seat;
use App\EventCatalog\Models\SeatMap;
use App\EventCatalog\Models\Venue;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-05b plan, task breakdown item 2 (TDD slice 2): POST
 * /v1/venues/{venue}/seat-maps creates a seat map template with its full
 * seat list in one document. Reads (events.view) and writes
 * (seat_maps.manage) are split the same way stage-05a's venues.php
 * already established (VenueEndpointsTest's own docblock), but this
 * endpoint has no read half yet (task-03).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->venue = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Venue::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::SeatMapsManage),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('seats')->where('tenant_id', $tenantId)->delete();
            DB::table('seat_maps')->where('tenant_id', $tenantId)->delete();
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
 * @return array<string, mixed>
 */
function seatMapCreatePayload(array $overrides = []): array
{
    return [
        'name' => 'Lower Bowl',
        'layout' => ['stage' => 'north'],
        'seats' => [
            ['section' => 'A', 'row' => '1', 'number' => '2', 'position_x' => 2, 'position_y' => 1],
            ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => 1, 'position_y' => 1],
            ['section' => 'B', 'row' => '2', 'number' => '1', 'position_x' => null, 'position_y' => null],
        ],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeSeatMapRow(string $tenantId, string $venueId, array $attributes = []): SeatMap
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => SeatMap::factory()->create(['tenant_id' => $tenantId, 'venue_id' => $venueId, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeSeatRow(string $tenantId, string $seatMapId, array $attributes = []): Seat
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Seat::factory()->create(['tenant_id' => $tenantId, 'seat_map_id' => $seatMapId, ...$attributes]),
    );
}

describe('POST /v1/venues/{venue}/seat-maps', function () {
    it('creates a seat map with its seats and returns the exact wire shape, seats ordered by section/row/number', function () {
        $response = $this->postJson(
            "/v1/venues/{$this->venue->id}/seat-maps",
            seatMapCreatePayload(),
        );

        $response->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJson([
                'venue_id' => $this->venue->id,
                'name' => 'Lower Bowl',
                'layout' => ['stage' => 'north'],
            ]);

        $body = $response->json();

        expect(Str::isUuid($body['id']))->toBeTrue()
            ->and($body['tenant_id'])->toBe($this->tenantId)
            ->and($body['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($body['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and(array_keys($body))->toBe([
                'id', 'tenant_id', 'venue_id', 'name', 'layout', 'seats', 'created_at', 'updated_at',
            ])
            ->and($body['seats'])->toHaveCount(3);

        // deterministic order: section, row, number, regardless of payload order
        expect(collect($body['seats'])->map(fn (array $seat) => [$seat['section'], $seat['row'], $seat['number']])->all())
            ->toBe([['A', '1', '1'], ['A', '1', '2'], ['B', '2', '1']]);

        foreach ($body['seats'] as $seat) {
            expect(Str::isUuid($seat['id']))->toBeTrue()
                ->and(array_keys($seat))->toBe(['id', 'section', 'row', 'number', 'position_x', 'position_y']);
        }
    });

    it('creates a seat map with an empty seats array', function () {
        $this->postJson(
            "/v1/venues/{$this->venue->id}/seat-maps",
            seatMapCreatePayload(['seats' => []]),
        )
            ->assertCreated()
            ->assertConformsToOpenApi()
            ->assertJsonCount(0, 'seats');
    });

    it('records exactly one activity_log row for the mutation', function () {
        $before = app(TenantTransaction::class)->asPlatform(
            fn () => DB::table('activity_log')->where('tenant_id', $this->tenantId)->count(),
        );

        $this->postJson("/v1/venues/{$this->venue->id}/seat-maps", seatMapCreatePayload())
            ->assertCreated();

        $after = app(TenantTransaction::class)->asPlatform(
            fn () => DB::table('activity_log')->where('tenant_id', $this->tenantId)->count(),
        );

        expect($after)->toBe($before + 1);
    });

    it('rejects an in-payload duplicate natural key with catalog.seat_map_duplicate_seats listing the offending positions', function () {
        $response = $this->postJson("/v1/venues/{$this->venue->id}/seat-maps", seatMapCreatePayload([
            'seats' => [
                ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
                ['section' => 'A', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
                ['section' => 'B', 'row' => '1', 'number' => '1', 'position_x' => null, 'position_y' => null],
            ],
        ]));

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'catalog.seat_map_duplicate_seats');

        expect(array_keys($response->json('errors')))->toBe(['seats.0', 'seats.1']);

        // the database never sees the write: no seat map row was created
        expect(app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => SeatMap::query()->where('tenant_id', $this->tenantId)->count(),
        ))->toBe(0);
    });

    it('rejects a duplicate template name on the same venue with catalog.seat_map_name_taken', function () {
        $this->postJson("/v1/venues/{$this->venue->id}/seat-maps", seatMapCreatePayload(['name' => 'Taken Name']))
            ->assertCreated();

        $this->postJson("/v1/venues/{$this->venue->id}/seat-maps", seatMapCreatePayload(['name' => 'Taken Name']))
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'catalog.seat_map_name_taken');
    });

    it('allows the same template name on a different venue', function () {
        $otherVenue = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => Venue::factory()->create(['tenant_id' => $this->tenantId]),
        );

        $this->postJson("/v1/venues/{$this->venue->id}/seat-maps", seatMapCreatePayload(['name' => 'Shared Name']))
            ->assertCreated();

        $this->postJson("/v1/venues/{$otherVenue->id}/seat-maps", seatMapCreatePayload(['name' => 'Shared Name']))
            ->assertCreated();
    });

    it('rejects an invalid payload with request.validation_failed carrying the errors map', function () {
        $response = $this->postJson("/v1/venues/{$this->venue->id}/seat-maps", seatMapCreatePayload(['name' => '']));

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('name');
    });

    it('rejects a seat missing a required natural-key field', function () {
        $this->postJson("/v1/venues/{$this->venue->id}/seat-maps", seatMapCreatePayload([
            'seats' => [
                ['section' => 'A', 'row' => '1', 'position_x' => null, 'position_y' => null],
            ],
        ]))
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('returns a request.not_found problem for a foreign tenant\'s venue', function () {
        $foreignVenue = app(TenantTransaction::class)->asTenant(
            $this->otherTenantId,
            fn () => Venue::factory()->create(['tenant_id' => $this->otherTenantId]),
        );

        $this->postJson("/v1/venues/{$foreignVenue->id}/seat-maps", seatMapCreatePayload())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for an unknown venue id', function () {
        $this->postJson('/v1/venues/'.Str::uuid7().'/seat-maps', seatMapCreatePayload())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->postJson("/v1/venues/{$this->venue->id}/seat-maps", seatMapCreatePayload(), ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking seat_maps.manage with missing_capability', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView)])
            ->postJson("/v1/venues/{$this->venue->id}/seat-maps", seatMapCreatePayload())
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('rejects a bearer with no membership in the asserted tenant', function () {
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->postJson("/v1/venues/{$this->venue->id}/seat-maps", seatMapCreatePayload())
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_access_denied');
    });
});

/*
 * Stage-05b plan, task breakdown item 3 (TDD slice 3): GET reads gate on
 * the existing events.view capability, not seat_maps.manage, matching
 * stage-05a's own read/write split (VenueEndpointsTest's docblock).
 */
describe('GET /v1/seat-maps/{seat_map}', function () {
    beforeEach(function (): void {
        $this->withHeaders([
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView),
            'X-Tenant-Id' => $this->tenantId,
        ]);
    });

    it('returns the full document with seats ordered by section/row/number regardless of insertion order', function () {
        $seatMap = makeSeatMapRow($this->tenantId, $this->venue->id, [
            'name' => 'Readable Map',
            'layout' => ['stage' => 'north'],
        ]);
        makeSeatRow($this->tenantId, $seatMap->id, ['section' => 'B', 'row' => '2', 'number' => '1']);
        makeSeatRow($this->tenantId, $seatMap->id, ['section' => 'A', 'row' => '1', 'number' => '2']);
        makeSeatRow($this->tenantId, $seatMap->id, ['section' => 'A', 'row' => '1', 'number' => '1']);

        $response = $this->getJson('/v1/seat-maps/'.$seatMap->id);

        $response->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('id', $seatMap->id)
            ->assertJsonPath('venue_id', $this->venue->id)
            ->assertJsonPath('name', 'Readable Map')
            ->assertJsonPath('layout', ['stage' => 'north'])
            ->assertJsonCount(3, 'seats');

        expect(collect($response->json('seats'))->map(fn (array $seat) => [$seat['section'], $seat['row'], $seat['number']])->all())
            ->toBe([['A', '1', '1'], ['A', '1', '2'], ['B', '2', '1']]);
    });

    it('returns a request.not_found problem for a foreign tenant\'s seat map', function () {
        $foreignVenue = app(TenantTransaction::class)->asTenant(
            $this->otherTenantId,
            fn () => Venue::factory()->create(['tenant_id' => $this->otherTenantId]),
        );
        $foreignSeatMap = makeSeatMapRow($this->otherTenantId, $foreignVenue->id);

        $this->getJson('/v1/seat-maps/'.$foreignSeatMap->id)
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for an unknown seat map id', function () {
        $this->getJson('/v1/seat-maps/'.Str::uuid7())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->getJson('/v1/seat-maps/'.Str::uuid7(), ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.view with missing_capability', function () {
        $seatMap = makeSeatMapRow($this->tenantId, $this->venue->id);

        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::SeatMapsManage)])
            ->getJson('/v1/seat-maps/'.$seatMap->id)
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('rejects a bearer with no membership in the asserted tenant', function () {
        $seatMap = makeSeatMapRow($this->tenantId, $this->venue->id);
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->getJson('/v1/seat-maps/'.$seatMap->id)
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_access_denied');
    });
});

describe('GET /v1/venues/{venue}/seat-maps', function () {
    beforeEach(function (): void {
        $this->withHeaders([
            'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView),
            'X-Tenant-Id' => $this->tenantId,
        ]);
    });

    it('returns the standard paginator envelope of SeatMapSummaryData with a correct seat_count', function () {
        $seatMap = makeSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Envelope Map']);
        makeSeatRow($this->tenantId, $seatMap->id, ['number' => '1']);
        makeSeatRow($this->tenantId, $seatMap->id, ['number' => '2']);

        $response = $this->getJson("/v1/venues/{$this->venue->id}/seat-maps");

        $response->assertOk()->assertConformsToOpenApi();

        $body = $response->json();

        expect(array_keys($body))->toContain('data', 'links', 'meta');

        $row = collect($body['data'])->firstWhere('name', 'Envelope Map');

        expect($row)->not->toBeNull()
            ->and($row['venue_id'])->toBe($this->venue->id)
            ->and($row['seat_count'])->toBe(2)
            ->and(array_keys($row))->toBe(['id', 'venue_id', 'name', 'seat_count', 'created_at', 'updated_at']);
    });

    it('scopes the list to the given venue only, excluding the tenant\'s other venues\' seat maps', function () {
        $otherVenue = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => Venue::factory()->create(['tenant_id' => $this->tenantId]),
        );
        makeSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Own Venue Map']);
        makeSeatMapRow($this->tenantId, $otherVenue->id, ['name' => 'Other Venue Map']);

        $names = collect($this->getJson("/v1/venues/{$this->venue->id}/seat-maps")->assertOk()->json('data'))->pluck('name');

        expect($names)->toContain('Own Venue Map')
            ->and($names)->not->toContain('Other Venue Map');
    });

    it('never returns a foreign tenant\'s seat maps', function () {
        $foreignVenue = app(TenantTransaction::class)->asTenant(
            $this->otherTenantId,
            fn () => Venue::factory()->create(['tenant_id' => $this->otherTenantId]),
        );
        makeSeatMapRow($this->otherTenantId, $foreignVenue->id, ['name' => 'Foreign Map']);
        makeSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Own Map']);

        $names = collect($this->getJson("/v1/venues/{$this->venue->id}/seat-maps")->assertOk()->json('data'))->pluck('name');

        expect($names)->toContain('Own Map')
            ->and($names)->not->toContain('Foreign Map');
    });

    it('filters by name with filter[name]', function () {
        makeSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Alpha Bowl']);
        makeSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'Beta Bowl']);

        $this->getJson("/v1/venues/{$this->venue->id}/seat-maps?filter[name]=alpha")
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Bowl');
    });

    it('sorts by the allowed sorts in both directions, defaulting to -created_at', function (?string $sort, array $expected) {
        makeSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'B Bowl', 'created_at' => '2026-01-02T00:00:00Z', 'updated_at' => '2026-01-02T00:00:00Z']);
        makeSeatMapRow($this->tenantId, $this->venue->id, ['name' => 'A Bowl', 'created_at' => '2026-01-03T00:00:00Z', 'updated_at' => '2026-01-03T00:00:00Z']);

        $query = $sort === null ? '' : '?sort='.$sort;

        $names = collect($this->getJson("/v1/venues/{$this->venue->id}/seat-maps{$query}")->assertOk()->json('data'))
            ->pluck('name')
            ->all();

        expect($names)->toBe($expected);
    })->with([
        'name' => ['name', ['A Bowl', 'B Bowl']],
        '-name' => ['-name', ['B Bowl', 'A Bowl']],
        'created_at' => ['created_at', ['B Bowl', 'A Bowl']],
        '-created_at' => ['-created_at', ['A Bowl', 'B Bowl']],
        'default (-created_at)' => [null, ['A Bowl', 'B Bowl']],
    ]);

    it('rejects an unknown filter with an invalid_query_parameter problem', function () {
        $this->getJson("/v1/venues/{$this->venue->id}/seat-maps?filter[unknown]=x")
            ->assertBadRequest()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('rejects an unknown sort with an invalid_query_parameter problem', function () {
        $this->getJson("/v1/venues/{$this->venue->id}/seat-maps?sort=venue_id")
            ->assertBadRequest()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'invalid_query_parameter');
    });

    it('returns a request.not_found problem for a foreign tenant\'s venue', function () {
        $foreignVenue = app(TenantTransaction::class)->asTenant(
            $this->otherTenantId,
            fn () => Venue::factory()->create(['tenant_id' => $this->otherTenantId]),
        );

        $this->getJson("/v1/venues/{$foreignVenue->id}/seat-maps")
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for an unknown venue id', function () {
        $this->getJson('/v1/venues/'.Str::uuid7().'/seat-maps')
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->getJson("/v1/venues/{$this->venue->id}/seat-maps", ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.view with missing_capability', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::SeatMapsManage)])
            ->getJson("/v1/venues/{$this->venue->id}/seat-maps")
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('rejects a bearer with no membership in the asserted tenant', function () {
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->getJson("/v1/venues/{$this->venue->id}/seat-maps")
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_access_denied');
    });
});
