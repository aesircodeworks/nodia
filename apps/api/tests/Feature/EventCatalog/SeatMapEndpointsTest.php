<?php

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
